<?php

namespace App\Cashier\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Cashier\DTO\PaymentIntent;
use App\Cashier\DTO\SubscriptionResult;
use App\Cashier\DTO\StripeAutoBillingData;
use App\Cashier\Services\StripeSubscriptionGateway;
use App\Cashier\Contracts\CheckoutHandlerInterface;
use App\Cashier\Contracts\PaymentGatewayResolverInterface;

/**
 * Stripe Subscription Controller (Category B)
 * ============================================
 *
 * Routes:
 *   GET  /cashier/stripe-subscription/checkout/{intent_uid}  — render card form + plan info
 *   POST /cashier/stripe-subscription/pay/{intent_uid}       — create subscription (or 3DS confirm)
 *
 * 3DS confirmation security: client supplies only intent_uid + confirm_3ds flag.
 * remote_subscription_id (sub_xxx) is server-stored in intent.remote_reference_id —
 * NEVER trust a client-supplied sub_xxx.
 */
class StripeSubscriptionController extends Controller
{
    protected function findIntent(string $uid): ?PaymentIntent
    {
        return app(CheckoutHandlerInterface::class)->findIntent($uid);
    }

    protected function getService(PaymentIntent $intent): StripeSubscriptionGateway
    {
        $service = app(PaymentGatewayResolverInterface::class)->resolve($intent->paymentGatewayId);

        if (!$service instanceof StripeSubscriptionGateway) {
            throw new \Exception('Gateway mismatch: expected StripeSubscriptionGateway');
        }
        return $service;
    }

    /**
     * GET /cashier/stripe-subscription/checkout/{intent_uid}?return_url=...
     */
    public function checkout(Request $request, string $intent_uid)
    {
        $intent = $this->findIntent($intent_uid);
        $returnUrl = $request->return_url ?? '/';

        if (!$intent) {
            return redirect()->away($returnUrl)
                ->with('alert-error', trans('cashier::messages.stripe_subscription.intent_not_found'));
        }

        if (!$intent->subscription) {
            return redirect()->away($returnUrl)
                ->with('alert-error', 'Intent missing subscription spec');
        }

        $service = $this->getService($intent);

        // Single-popup 3DS pattern (default_incomplete): no SetupIntent prep.
        // Frontend creates pm_xxx via stripe.createPaymentMethod (no 3DS),
        // server creates Subscription with payment_behavior=default_incomplete,
        // returns invoice PaymentIntent's client_secret → 1 confirmCardPayment popup.
        $remotePlan     = $service->getRemotePlan($intent->subscription->remotePlanId);
        $stripeCustomer = $service->getStripeCustomer($intent->payer->uid)
            ?? $service->createStripeCustomer($intent->payer->uid, $intent->payer->name);

        return view('cashier::stripe-subscription.checkout', [
            'intent'         => $intent,
            'remotePlan'     => $remotePlan,
            'returnUrl'      => $returnUrl,
            'publishableKey' => $service->getPublishableKey(),
        ]);
    }

    /**
     * POST /cashier/stripe-subscription/pay/{intent_uid}
     *
     * Body modes:
     *   - First hit: { stripe_payment_method, return_url } → create subscription
     *   - 3DS confirm: { confirm_3ds: true, return_url }   → re-check intent.remote_reference_id
     */
    public function pay(Request $request, string $intent_uid)
    {
        try {
            $intent = $this->findIntent($intent_uid);
            if (!$intent) {
                return response()->json(['error' => trans('cashier::messages.stripe_subscription.intent_not_found')], 404);
            }

            $returnUrl = $request->return_url ?? '/';
            $service = $this->getService($intent);
            $handler = app(CheckoutHandlerInterface::class);

            // 3DS re-confirmation — server uses intent.remote_reference_id
            if ($request->confirm_3ds && !$request->stripe_payment_method) {
                return $this->handle3dsConfirmation($intent, $service, $handler, $returnUrl);
            }

            if (empty($request->stripe_payment_method)) {
                return response()->json(['error' => 'Payment method ID is required'], 422);
            }

            // Get-or-create Stripe customer
            $stripeCustomer = $service->getStripeCustomer($intent->payer->uid)
                ?? $service->createStripeCustomer($intent->payer->uid, $intent->payer->name);

            // Service constructor sets Stripe API key — direct SDK call OK
            $pm = \Stripe\PaymentMethod::retrieve($request->stripe_payment_method);

            $billingData = new StripeAutoBillingData([
                'stripe_payment_method' => $request->stripe_payment_method,
                'stripe_customer'       => $stripeCustomer->id,
                'card_type'             => ucfirst($pm->card->brand ?? ''),
                'last_4'                => $pm->card->last4 ?? '',
                'exp_month'             => $pm->card->exp_month ?? 0,
                'exp_year'              => $pm->card->exp_year ?? 0,
            ]);

            // Persist payment method first so handler.onSubscriptionCreated can reference it
            $handler->createPaymentMethod($intent, $billingData->toArray());

            $result = $service->createSubscription($intent, $billingData->toArray());

            return $this->dispatchResult($result, $intent, $handler, $returnUrl);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * Server reads sub_xxx from intent.remote_reference_id (server-stored), re-checks Stripe status.
     * Client's only auth is intent_uid (URL path) — no sub_xxx accepted from request body.
     */
    protected function handle3dsConfirmation(
        PaymentIntent $intent,
        StripeSubscriptionGateway $service,
        CheckoutHandlerInterface $handler,
        string $returnUrl
    ) {
        $subId = $intent->remoteReferenceId;
        if (!$subId) {
            return response()->json(['error' => 'Intent has no pending subscription to confirm'], 422);
        }

        $remoteSub = $service->getRemoteSubscription($subId);

        // Stripe sometimes lags transitioning out of incomplete after 3DS — short retry
        if ($remoteSub->isIncomplete()) {
            sleep(2);
            $remoteSub = $service->getRemoteSubscription($subId);
        }

        if (!$remoteSub->isActive()) {
            return response()->json([
                'error' => trans('cashier::messages.stripe_subscription.payment_failed'),
            ], 422);
        }

        // Card display info (best-effort — fall back to nothing if Stripe lookup fails)
        $pmData = [];
        try {
            $pm = $service->getRemotePaymentMethod($subId);
            if ($pm) {
                $pmData = [
                    'card_type' => $pm->cardType,
                    'last_4'    => $pm->last4,
                ];
            }
        } catch (\Throwable $e) {
            // Non-fatal — proceed without card info
        }

        $handler->onSubscriptionCreated($intent, [
            'remote_subscription_id' => $remoteSub->id,
            'remote_customer_id'     => $remoteSub->remoteCustomerId,
            'status'                 => $remoteSub->status,
            'current_period_end'     => $remoteSub->currentPeriodEnd?->getTimestamp(),
            'payment_method_data'    => $pmData,
        ]);

        return response()->json([
            'success'      => true,
            'redirect_url' => $returnUrl,
        ]);
    }

    private function dispatchResult(
        SubscriptionResult $result,
        PaymentIntent $intent,
        CheckoutHandlerInterface $handler,
        string $returnUrl
    ) {
        switch ($result->status) {
            case SubscriptionResult::STATUS_ACTIVE:
                $handler->onSubscriptionCreated($intent, [
                    'remote_subscription_id' => $result->remoteSubscriptionId,
                    'remote_customer_id'     => $result->remoteCustomerId,
                    'status'                 => 'active',
                    'current_period_end'     => $result->currentPeriodEnd,
                    'payment_method_data'    => $result->paymentMethodData,
                ]);
                return response()->json([
                    'success'      => true,
                    'redirect_url' => $returnUrl,
                ]);

            case SubscriptionResult::STATUS_REQUIRES_ACTION:
                $handler->onSubscriptionRequiresAuth(
                    $intent,
                    $result->clientSecret,
                    $result->remoteSubscriptionId
                );
                return response()->json([
                    'requires_action'        => true,
                    'client_secret'          => $result->clientSecret,
                    'payment_method_data'    => $result->paymentMethodData,
                ]);

            case SubscriptionResult::STATUS_FAILED:
            default:
                return response()->json([
                    'error' => $result->error ?? trans('cashier::messages.stripe_subscription.payment_failed'),
                ], 422);
        }
    }
}
