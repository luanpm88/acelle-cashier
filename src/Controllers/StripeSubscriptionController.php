<?php

namespace App\Cashier\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Model\Invoice;
use App\Cashier\Services\StripeSubscriptionGateway;
use App\Cashier\Contracts\CheckoutHandlerInterface;
use App\Cashier\Contracts\PaymentGatewayResolverInterface;

class StripeSubscriptionController extends Controller
{
    protected function findInvoice(string $invoiceUid): ?Invoice
    {
        return Invoice::findByUid($invoiceUid);
    }

    /**
     * Resolve payment_gateway_id from the request into a ready-to-use StripeSubscriptionGateway.
     *
     * Credentials (pub_key, secret_key, webhook_secret) are loaded from DB via the resolver —
     * cashier has no knowledge of how the main app stores them. This replaces the previous
     * gateway_token pattern (encrypted credentials in URL) with a clean DI lookup.
     */
    protected function getService(Request $request): StripeSubscriptionGateway
    {
        $service = app(PaymentGatewayResolverInterface::class)
            ->resolve($request->payment_gateway_id);

        if (!$service instanceof StripeSubscriptionGateway) {
            throw new \Exception('Invalid payment gateway for Stripe Subscription checkout');
        }

        return $service;
    }

    /**
     * GET /cashier/stripe-subscription/checkout/{invoice_uid}
     *     ?payment_gateway_id=xxx&return_url=xxx&remote_plan_id=xxx
     *
     * Display the Stripe Elements card input form for subscription checkout.
     * Main app redirects user here after creating an invoice + selecting a plan.
     */
    public function checkout(Request $request, $invoice_uid)
    {
        $invoice = $this->findInvoice($invoice_uid);
        $service = $this->getService($request);
        $returnUrl = $request->return_url ?? '/';

        if (!$invoice) {
            return redirect()->away($returnUrl)
                ->with('alert-error', trans('cashier::messages.stripe_subscription.invoice_not_found'));
        }

        if (!$invoice->isNew()) {
            return redirect()->away($returnUrl)
                ->with('alert-success', trans('cashier::messages.already_paid'));
        }

        // Fetch plan details from Stripe API for display (name, price, interval)
        $remotePlan = $service->getRemotePlan($request->remote_plan_id);

        // Get or create Stripe Customer using payer's local UID
        $stripeCustomer = $service->getStripeCustomer($invoice->getPayerUid())
            ?? $service->createStripeCustomer($invoice->getPayerUid(), $invoice->getPayerName());

        // Create SetupIntent — JS will use this clientSecret to call stripe.confirmCardSetup()
        $clientSecret = $service->getSetupIntentSecret($stripeCustomer);

        return view('cashier::stripe-subscription.checkout', [
            // Pass-through params: view sends these back to POST /pay via AJAX
            'paymentGatewayId' => $request->payment_gateway_id, // app's gateway record UID (resolver input)
            'returnUrl' => $returnUrl,                        // redirect after success
            'remotePlanId' => $request->remote_plan_id,       // Stripe Price ID

            // Display: plan info + invoice amount
            'invoice' => $invoice,
            'remotePlan' => $remotePlan,

            // Stripe JS: init Stripe Elements + confirm card setup
            'clientSecret' => $clientSecret,
            'publishableKey' => $service->getPublishableKey(),
        ]);
    }

    /**
     * POST /cashier/stripe-subscription/pay/{invoice_uid}
     *
     * Called via AJAX from the checkout page after user confirms their card.
     *
     * Two modes:
     * 1. Normal: stripe_payment_method present → create Stripe Subscription
     * 2. 3DS re-confirmation: remote_subscription_id present, no stripe_payment_method
     *    → subscription already exists on Stripe, just activate locally
     */
    public function pay(Request $request, $invoice_uid)
    {
        try {
            $invoice = $this->findInvoice($invoice_uid);
            $service = $this->getService($request);
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

            // Main app's callback handler — receives payment results
            $handler = app(CheckoutHandlerInterface::class);

            // 3DS re-confirmation: subscription already created on Stripe, activate locally
            if ($request->remote_subscription_id && !$request->stripe_payment_method) {
                return $this->handle3dsConfirmation($request, $invoice, $service, $handler, $paymentGatewayId, $returnUrl);
            }

            if (empty($request->stripe_payment_method)) {
                return response()->json([
                    'error' => 'Payment method ID is required. Please try again.',
                ], 422);
            }

            // Create Stripe Subscription with the confirmed payment method
            $result = $service->createRemoteSubscription($invoice, $request->remote_plan_id, [
                'stripe_payment_method' => $request->stripe_payment_method,
            ]);

            if ($result->success) {
                // Notify main app: save remote IDs, create payment method record, mark invoice paid
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
                // Card requires 3DS — return client_secret for JS to call stripe.confirmCardPayment()
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
     * Handle 3DS re-confirmation.
     *
     * After JS calls stripe.confirmCardPayment() successfully, the browser POSTs back
     * with remote_subscription_id (no stripe_payment_method). We verify the subscription
     * is active on Stripe, then notify the main app to activate locally.
     */
    protected function handle3dsConfirmation(Request $request, $invoice, $service, $handler, $paymentGatewayId, $returnUrl)
    {
        $remoteSub = $service->getRemoteSubscription($request->remote_subscription_id);

        // Stripe may take a moment to transition from 'incomplete' after 3DS confirmation
        if ($remoteSub->isIncomplete()) {
            sleep(2);
            $remoteSub = $service->getRemoteSubscription($request->remote_subscription_id);
        }

        if ($remoteSub->isActive()) {
            // Get card info: prefer what JS already has, fallback to Stripe API
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

            // Notify main app: save remote IDs, mark invoice paid, activate subscription
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
