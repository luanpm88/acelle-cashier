<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Acelle\Model\Subscription;
use Acelle\Model\PaymentGateway;
use Acelle\Cashier\Services\StripeSubscriptionGateway;
use Acelle\Cashier\Services\BraintreeSubscriptionGateway;
use Acelle\Library\Contracts\RemoteSubscriptionGatewayInterface;
use Illuminate\Support\Facades\Log;

class RemoteSubscriptionWebhookController extends Controller
{
    public function stripeSubscription(Request $request)
    {
        $gateway = PaymentGateway::where('type', StripeSubscriptionGateway::TYPE)->active()->first();

        if (!$gateway) {
            Log::warning('Stripe subscription webhook received but no active gateway found');
            return response()->json(['status' => 'no_gateway'], 200);
        }

        $service = $gateway->getService();
        if (!($service instanceof RemoteSubscriptionGatewayInterface)) {
            return response()->json(['status' => 'invalid_service'], 400);
        }

        try {
            $parsed = $service->parseWebhookPayload(
                $request->getContent(),
                $request->headers->all()
            );
        } catch (\Exception $e) {
            Log::error('Stripe subscription webhook signature verification failed: ' . $e->getMessage());
            return response()->json(['error' => trans('cashier::messages.webhook.invalid_signature')], 400);
        }

        return $this->handleWebhookEvent($parsed, $gateway);
    }

    public function braintreeSubscription(Request $request)
    {
        $gateway = PaymentGateway::where('type', BraintreeSubscriptionGateway::TYPE)->active()->first();

        if (!$gateway) {
            Log::warning('Braintree subscription webhook received but no active gateway found');
            return response()->json(['status' => 'no_gateway'], 200);
        }

        $service = $gateway->getService();
        if (!($service instanceof RemoteSubscriptionGatewayInterface)) {
            return response()->json(['status' => 'invalid_service'], 400);
        }

        try {
            $parsed = $service->parseWebhookPayload(
                $request->input('bt_payload', ''),
                ['bt-signature' => $request->input('bt_signature', '')]
            );
        } catch (\Exception $e) {
            Log::error('Braintree subscription webhook verification failed: ' . $e->getMessage());
            return response()->json(['error' => trans('cashier::messages.webhook.invalid_payload')], 400);
        }

        return $this->handleWebhookEvent($parsed, $gateway);
    }

    protected function handleWebhookEvent(array $parsed, PaymentGateway $gateway)
    {
        $event = $parsed['event'];
        $data = $parsed['data'];
        $remoteSubId = $data['id'] ?? null;

        if (!$remoteSubId) {
            Log::warning("Webhook event {$event} has no subscription ID");
            return response()->json(['status' => 'no_sub_id'], 200);
        }

        $subscription = Subscription::where('remote_subscription_id', $remoteSubId)->first();

        if (!$subscription) {
            Log::info("Webhook event {$event} for unknown remote subscription: {$remoteSubId}");
            return response()->json(['status' => 'unknown_subscription'], 200);
        }

        Log::info("Processing webhook event {$event} for subscription {$subscription->uid} (remote: {$remoteSubId})");

        switch ($event) {
            case 'customer.subscription.updated':
                $this->handleSubscriptionUpdated($subscription, $data, $gateway);
                break;

            case 'customer.subscription.deleted':
                $this->handleSubscriptionDeleted($subscription, $data);
                break;

            case 'invoice.paid':
                $this->handleInvoicePaid($subscription, $data, $gateway);
                break;

            case 'invoice.payment_failed':
                $this->handlePaymentFailed($subscription, $data);
                break;

            default:
                Log::info("Unhandled webhook event: {$event}");
        }

        return response()->json(['status' => 'processed'], 200);
    }

    protected function handleSubscriptionUpdated(Subscription $subscription, array $data, PaymentGateway $gateway)
    {
        try {
            $service = $gateway->getService();
            $remoteSub = $service->getRemoteSubscription($subscription->remote_subscription_id);

            // Sync period end date
            if ($remoteSub->currentPeriodEnd && $subscription->isActive()) {
                $subscription->current_period_ends_at = $remoteSub->currentPeriodEnd;
                $subscription->last_synced_at = now();
                $subscription->save();
            }

            Log::info("Subscription {$subscription->uid} updated from webhook. Remote status: {$remoteSub->status}");
        } catch (\Exception $e) {
            Log::error("Error handling subscription updated webhook for {$subscription->uid}: " . $e->getMessage());
        }
    }

    protected function handleSubscriptionDeleted(Subscription $subscription, array $data)
    {
        try {
            if ($subscription->isActive()) {
                $subscription->cancelNow();
                Log::info("Subscription {$subscription->uid} cancelled via webhook (remote subscription deleted)");
            }
        } catch (\Exception $e) {
            Log::error("Error handling subscription deleted webhook for {$subscription->uid}: " . $e->getMessage());
        }
    }

    protected function handleInvoicePaid(Subscription $subscription, array $data, PaymentGateway $gateway)
    {
        try {
            if ($subscription->isActive()) {
                // Sync with remote to update period
                $service = $gateway->getService();
                $remoteSub = $service->getRemoteSubscription($subscription->remote_subscription_id);

                if ($remoteSub->currentPeriodEnd) {
                    $subscription->current_period_ends_at = $remoteSub->currentPeriodEnd;
                    $subscription->last_synced_at = now();
                    $subscription->save();
                }

                Log::info("Invoice paid webhook processed for subscription {$subscription->uid}");
            }
        } catch (\Exception $e) {
            Log::error("Error handling invoice paid webhook for {$subscription->uid}: " . $e->getMessage());
        }
    }

    protected function handlePaymentFailed(Subscription $subscription, array $data)
    {
        Log::warning("Payment failed for subscription {$subscription->uid} (remote: {$subscription->remote_subscription_id})");
    }
}
