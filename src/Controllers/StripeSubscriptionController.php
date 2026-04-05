<?php

namespace App\Cashier\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Model\Invoice;
use App\Cashier\Services\StripeSubscriptionGateway;
use App\Cashier\Contracts\CheckoutHandlerInterface;

class StripeSubscriptionController extends Controller
{
    protected function findOwnedInvoice(Request $request, string $invoiceUid): ?Invoice
    {
        $customer = $request->user()?->customer;

        if (!$customer) {
            return null;
        }

        return $customer->invoices()->where('invoices.uid', $invoiceUid)->first();
    }

    protected function getGatewayConfig(Request $request)
    {
        return json_decode(decrypt($request->gateway_token), true);
    }

    protected function getServiceFromConfig(array $config)
    {
        return new StripeSubscriptionGateway(
            $config['pub_key'],
            $config['secret_key'],
            $config['webhook_secret'] ?? null
        );
    }

    /**
     * GET /cashier/stripe-subscription/checkout/{invoice_uid}
     *     ?gateway_token=xxx&payment_gateway_id=xxx&return_url=xxx&remote_plan_id=xxx
     *
     * Display the card input form for subscription checkout.
     * remote_plan_id is resolved by the main app before redirecting here.
     */
    public function checkout(Request $request, $invoice_uid)
    {
        $invoice = $this->findOwnedInvoice($request, $invoice_uid);
        $config = $this->getGatewayConfig($request);
        $service = $this->getServiceFromConfig($config);
        $returnUrl = $request->return_url ?? '/';

        if (!$invoice) {
            return redirect()->away($returnUrl)
                ->with('alert-error', trans('cashier::messages.stripe_subscription.invoice_not_found'));
        }

        if (!$invoice->isNew()) {
            return redirect()->away($returnUrl)
                ->with('alert-success', trans('cashier::messages.already_paid'));
        }

        // Fetch plan details from Stripe for display
        $remotePlan = $service->getRemotePlan($request->remote_plan_id);

        return view('cashier::stripe-subscription.checkout', [
            'gatewayToken' => $request->gateway_token,
            'paymentGatewayId' => $request->payment_gateway_id,
            'returnUrl' => $returnUrl,
            'remotePlanId' => $request->remote_plan_id,
            'invoice' => $invoice,
            'remotePlan' => $remotePlan,
            'clientSecret' => $service->getClientSecret($invoice->customer->uid, $invoice->billing_email ?: $invoice->customer->user->email),
            'publishableKey' => $service->getPublishableKey(),
        ]);
    }

    /**
     * POST /cashier/stripe-subscription/pay/{invoice_uid}
     *
     * Process subscription creation after card confirmation.
     * Also handles 3DS re-confirmation (when remote_subscription_id is sent without payment_method_id).
     */
    public function pay(Request $request, $invoice_uid)
    {
        try {
            $invoice = $this->findOwnedInvoice($request, $invoice_uid);
            $config = $this->getGatewayConfig($request);
            $service = $this->getServiceFromConfig($config);
            $returnUrl = $request->return_url ?? '/';
            $paymentGatewayId = $request->payment_gateway_id;

            if (!$invoice) {
                return response()->json([
                    'error' => trans('cashier::messages.stripe_subscription.invoice_not_found'),
                ], 404);
            }

            if (!$invoice->isNew()) {
                return response()->json([
                    'error' => trans('cashier::messages.already_paid'),
                ], 422);
            }

            $handler = app(CheckoutHandlerInterface::class);

            // Handle 3DS re-confirmation
            if ($request->remote_subscription_id && !$request->payment_method_id) {
                return $this->handle3dsConfirmation($request, $invoice, $service, $handler, $paymentGatewayId, $returnUrl);
            }

            if (empty($request->payment_method_id)) {
                return response()->json([
                    'error' => 'Payment method ID is required. Please try again.',
                ], 422);
            }

            $result = $service->createRemoteSubscription($invoice, $request->remote_plan_id, [
                'payment_method_id' => $request->payment_method_id,
            ]);

            if ($result->success) {
                $handler->onRemoteSubscriptionCreated($invoice, $paymentGatewayId, [
                    'remote_subscription_id' => $result->remoteSubscriptionId,
                    'remote_customer_id' => $result->remoteCustomerId,
                    'status' => $result->status,
                    'current_period_end' => $result->currentPeriodEnd,
                    'payment_method_data' => $result->paymentMethodData ?? [],
                ]);

                return response()->json([
                    'success' => true,
                    'redirect_url' => $returnUrl,
                ]);
            } elseif ($result->status === 'requires_action') {
                return response()->json([
                    'requires_action' => true,
                    'client_secret' => $result->metadata['client_secret'] ?? null,
                    'remote_subscription_id' => $result->remoteSubscriptionId,
                    'payment_method_data' => $result->paymentMethodData,
                ]);
            } else {
                return response()->json([
                    'error' => $result->error ?? trans('cashier::messages.stripe_subscription.payment_failed'),
                ], 422);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Handle 3DS re-confirmation: subscription already created on Stripe, activate locally.
     */
    protected function handle3dsConfirmation(Request $request, $invoice, $service, $handler, $paymentGatewayId, $returnUrl)
    {
        $remoteSub = $service->getRemoteSubscription($request->remote_subscription_id);

        if ($remoteSub->isIncomplete()) {
            sleep(2);
            $remoteSub = $service->getRemoteSubscription($request->remote_subscription_id);
        }

        if ($remoteSub->isActive()) {
            $pmData = [];
            if ($request->card_type && $request->card_last4) {
                $pmData = [
                    'card_type' => $request->card_type,
                    'last_4' => $request->card_last4,
                ];
            } else {
                try {
                    $remotePaymentMethod = $service->getRemotePaymentMethod($remoteSub->id);
                    $pmData = $remotePaymentMethod ? [
                        'card_type' => $remotePaymentMethod->cardType,
                        'last_4' => $remotePaymentMethod->last4,
                    ] : [];
                } catch (\Throwable $e) {
                    // Card info fetch failed — not critical
                }
            }

            $handler->onRemoteSubscriptionCreated($invoice, $paymentGatewayId, [
                'remote_subscription_id' => $remoteSub->id,
                'remote_customer_id' => $remoteSub->remoteCustomerId,
                'status' => $remoteSub->status,
                'current_period_end' => $remoteSub->currentPeriodEnd,
                'payment_method_data' => $pmData,
            ]);

            return response()->json([
                'success' => true,
                'redirect_url' => $returnUrl,
            ]);
        } else {
            return response()->json([
                'error' => trans('cashier::messages.stripe_subscription.payment_failed'),
            ], 422);
        }
    }
}
