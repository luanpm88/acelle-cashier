<?php

namespace App\Cashier\Services;

use App\Library\Contracts\RemoteSubscriptionGatewayInterface;
use App\Library\DTOs\RemotePlanDTO;
use App\Library\DTOs\RemoteSubscriptionDTO;
use App\Library\DTOs\RemotePaymentMethodDTO;
use App\Library\DTOs\CreateRemoteSubscriptionResult;
use App\Model\Invoice;
use App\Model\Transaction;
use App\Cashier\Contracts\PaymentMethodInfoInterface;
use Carbon\Carbon;

class StripeSubscriptionGateway implements RemoteSubscriptionGatewayInterface
{
    public const TYPE = 'stripe-subscription';

    protected string $publishableKey;
    protected string $secretKey;
    protected ?string $webhookSecret;
    protected bool $active = false;

    public function __construct(string $publishableKey, string $secretKey, ?string $webhookSecret = null)
    {
        $this->publishableKey = $publishableKey;
        $this->secretKey = $secretKey;
        $this->webhookSecret = $webhookSecret;

        if ($publishableKey && $secretKey) {
            $this->active = true;
            \Stripe\Stripe::setApiKey($this->secretKey);
            \Stripe\Stripe::setApiVersion('2023-10-16');
        }
    }

    // ─── PaymentGatewayInterface (base) ───

    public function getCheckoutUrl(Invoice $invoice, string $paymentGatewayId, string $returnUrl = '/'): string
    {
        return action('\App\Cashier\Controllers\StripeSubscriptionController@checkout', [
            'invoice_uid' => $invoice->uid,
            'payment_gateway_id' => $paymentGatewayId,
            'return_url' => $returnUrl,
        ]);
    }

    public function supportsAutoBilling(): bool
    {
        return false; // Provider manages billing
    }

    public function autoCharge($invoice, PaymentMethodInfoInterface $paymentMethodInfo)
    {
        throw new \Exception('StripeSubscription does not support local auto-charge. Billing is managed by Stripe.');
    }

    public function allowManualReviewingOfTransaction(): bool
    {
        return false;
    }

    public function getMinimumChargeAmount($currency)
    {
        return 0;
    }

    public function verify(Transaction $transaction)
    {
        // No-op: verification is via webhook
    }

    public function getMethodTitle($billingData)
    {
        return $billingData['card_type'] ?? 'Card';
    }

    public function getMethodInfo($billingData)
    {
        return '*** *** *** ' . ($billingData['last_4'] ?? '****');
    }

    // ─── RemoteSubscriptionGatewayInterface ───

    public function getPublishableKey(): string
    {
        return $this->publishableKey;
    }

    public function getWebhookSecret(): ?string
    {
        return $this->webhookSecret;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getRemotePlans(): array
    {
        $prices = \Stripe\Price::all([
            'active' => true,
            'type' => 'recurring',
            'limit' => 100,
            'expand' => ['data.product'],
        ]);

        $result = [];
        foreach ($prices->data as $price) {
            $product = $price->product;
            $trialDays = $price->recurring->trial_period_days ?? null;
            $result[] = new RemotePlanDTO(
                id: $price->id,
                name: is_object($product) ? $product->name : $price->id,
                price: $price->unit_amount / 100,
                currency: strtoupper($price->currency),
                intervalCount: $price->recurring->interval_count,
                intervalUnit: $price->recurring->interval,
                status: $price->active ? 'active' : 'inactive',
                trialDays: $trialDays ? (int) $trialDays : null,
                metadata: [
                    'stripe_product_id' => is_object($product) ? $product->id : $price->product,
                    'stripe_price_id' => $price->id,
                ],
            );
        }

        return $result;
    }

    public function getRemotePlan(string $remotePlanId): RemotePlanDTO
    {
        $price = \Stripe\Price::retrieve([
            'id' => $remotePlanId,
            'expand' => ['product'],
        ]);
        $product = $price->product;

        $trialDays = $price->recurring->trial_period_days ?? null;

        return new RemotePlanDTO(
            id: $price->id,
            name: is_object($product) ? $product->name : $price->id,
            price: $price->unit_amount / 100,
            currency: strtoupper($price->currency),
            intervalCount: $price->recurring->interval_count,
            intervalUnit: $price->recurring->interval,
            status: $price->active ? 'active' : 'inactive',
            trialDays: $trialDays ? (int) $trialDays : null,
            metadata: [
                'stripe_product_id' => is_object($product) ? $product->id : $price->product,
                'stripe_price_id' => $price->id,
            ],
        );
    }

    public function getRemoteSubscription(string $remoteSubscriptionId): RemoteSubscriptionDTO
    {
        $sub = \Stripe\Subscription::retrieve([
            'id' => $remoteSubscriptionId,
            'expand' => ['latest_invoice'],
        ]);

        $latestAmount = null;
        $latestStatus = null;
        if ($sub->latest_invoice && is_object($sub->latest_invoice)) {
            $latestAmount = $sub->latest_invoice->amount_paid / 100;
            $latestStatus = $sub->latest_invoice->status;
        }

        return new RemoteSubscriptionDTO(
            id: $sub->id,
            status: $sub->status,
            remotePlanId: $sub->items->data[0]->price->id ?? '',
            remoteCustomerId: $sub->customer,
            currentPeriodEnd: Carbon::createFromTimestamp($sub->current_period_end),
            currentPeriodStart: Carbon::createFromTimestamp($sub->current_period_start),
            canceledAt: $sub->canceled_at ? Carbon::createFromTimestamp($sub->canceled_at) : null,
            latestInvoiceAmount: $latestAmount,
            latestInvoiceStatus: $latestStatus,
            metadata: ['stripe_subscription' => $sub->toArray()],
        );
    }

    public function createRemoteSubscription(
        Invoice $invoice,
        string $remotePlanId,
        array $checkoutData
    ): CreateRemoteSubscriptionResult {
        try {
            $customer = $invoice->customer;
            $email = $invoice->billing_email ?: $customer->user->email;
            $stripeCustomer = $this->getOrCreateStripeCustomer($customer->uid, $email);

            $paymentMethodId = $checkoutData['payment_method_id'];
            $paymentMethod = \Stripe\PaymentMethod::retrieve($paymentMethodId);

            // Attach PM to customer — skip if already attached (e.g. from confirmCardSetup)
            if ($paymentMethod->customer !== $stripeCustomer->id) {
                $paymentMethod->attach(['customer' => $stripeCustomer->id]);
            }

            \Stripe\Customer::update($stripeCustomer->id, [
                'invoice_settings' => ['default_payment_method' => $paymentMethodId],
            ]);

            $stripeSubscription = \Stripe\Subscription::create([
                'customer' => $stripeCustomer->id,
                'items' => [['price' => $remotePlanId]],
                'default_payment_method' => $paymentMethodId,
                'payment_behavior' => 'default_incomplete',
                'expand' => ['latest_invoice.payment_intent'],
                'metadata' => [
                    'local_invoice_uid' => $invoice->uid,
                    'local_customer_uid' => $customer->uid,
                ],
            ]);

            $cardInfo = [];
            if (isset($paymentMethod->card)) {
                $cardInfo = [
                    'card_type' => ucfirst($paymentMethod->card->brand),
                    'last_4' => $paymentMethod->card->last4,
                ];
            }

            if ($stripeSubscription->status === 'incomplete') {
                $paymentIntent = $stripeSubscription->latest_invoice->payment_intent ?? null;
                $piStatus = $paymentIntent?->status;

                // 3D Secure or other client-side action needed
                if ($paymentIntent && in_array($piStatus, ['requires_action', 'requires_confirmation'])) {
                    return new CreateRemoteSubscriptionResult(
                        success: false,
                        remoteSubscriptionId: $stripeSubscription->id,
                        remoteCustomerId: $stripeCustomer->id,
                        remotePlanId: $remotePlanId,
                        currentPeriodEnd: Carbon::createFromTimestamp($stripeSubscription->current_period_end),
                        status: 'requires_action',
                        error: null,
                        paymentMethodData: $cardInfo ?? [],
                        metadata: [
                            'client_secret' => $paymentIntent->client_secret,
                            'stripe_subscription_id' => $stripeSubscription->id,
                        ],
                    );
                }

                // PaymentIntent succeeded but sub hasn't transitioned yet — re-fetch
                if ($piStatus === 'succeeded') {
                    $stripeSubscription = \Stripe\Subscription::retrieve($stripeSubscription->id);
                }
            }

            return new CreateRemoteSubscriptionResult(
                success: in_array($stripeSubscription->status, ['active', 'trialing']),
                remoteSubscriptionId: $stripeSubscription->id,
                remoteCustomerId: $stripeCustomer->id,
                remotePlanId: $remotePlanId,
                currentPeriodEnd: Carbon::createFromTimestamp($stripeSubscription->current_period_end),
                status: $stripeSubscription->status,
                paymentMethodData: $cardInfo,
                metadata: ['stripe_subscription_id' => $stripeSubscription->id],
            );
        } catch (\Stripe\Exception\CardException $e) {
            return new CreateRemoteSubscriptionResult(
                success: false,
                remoteSubscriptionId: null,
                remoteCustomerId: null,
                remotePlanId: $remotePlanId,
                currentPeriodEnd: null,
                status: 'failed',
                error: $e->getError()->message,
            );
        } catch (\Throwable $e) {
            return new CreateRemoteSubscriptionResult(
                success: false,
                remoteSubscriptionId: null,
                remoteCustomerId: null,
                remotePlanId: $remotePlanId,
                currentPeriodEnd: null,
                status: 'failed',
                error: $e->getMessage(),
            );
        }
    }

    public function getRemotePaymentMethod(string $remoteSubscriptionId): ?RemotePaymentMethodDTO
    {
        $sub = \Stripe\Subscription::retrieve($remoteSubscriptionId);

        $paymentMethodId = $sub->default_payment_method;
        // Handle expanded objects — extract the ID string
        if (is_object($paymentMethodId) && isset($paymentMethodId->id)) {
            $paymentMethodId = $paymentMethodId->id;
        }
        if (!$paymentMethodId || !is_string($paymentMethodId)) {
            // Fall back to customer's default payment method
            $customer = \Stripe\Customer::retrieve($sub->customer);
            $paymentMethodId = $customer->invoice_settings->default_payment_method ?? null;
            if (is_object($paymentMethodId) && isset($paymentMethodId->id)) {
                $paymentMethodId = $paymentMethodId->id;
            }
        }

        if (!$paymentMethodId || !is_string($paymentMethodId)) {
            return null;
        }

        $pm = \Stripe\PaymentMethod::retrieve($paymentMethodId);

        if ($pm->type === 'card' && $pm->card) {
            return new RemotePaymentMethodDTO(
                cardType: ucfirst($pm->card->brand),
                last4: $pm->card->last4,
                expirationDate: $pm->card->exp_month . '/' . $pm->card->exp_year,
                email: null,
                type: 'card',
            );
        }

        return new RemotePaymentMethodDTO(
            cardType: null,
            last4: null,
            expirationDate: null,
            email: null,
            type: $pm->type,
        );
    }

    public function cancelRemoteSubscription(string $remoteSubscriptionId): void
    {
        \Stripe\Subscription::update($remoteSubscriptionId, [
            'cancel_at_period_end' => true,
        ]);
    }

    public function updateRemoteSubscriptionPlan(
        string $remoteSubscriptionId,
        string $newRemotePlanId
    ): RemoteSubscriptionDTO {
        $sub = \Stripe\Subscription::retrieve($remoteSubscriptionId);

        \Stripe\Subscription::update($remoteSubscriptionId, [
            'items' => [[
                'id' => $sub->items->data[0]->id,
                'price' => $newRemotePlanId,
            ]],
            'proration_behavior' => 'create_prorations',
        ]);

        return $this->getRemoteSubscription($remoteSubscriptionId);
    }

    public function parseWebhookPayload(string $payload, array $headers): array
    {
        $sigHeader = $headers['stripe-signature'] ?? ($headers['Stripe-Signature'] ?? '');

        $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $this->webhookSecret);

        return [
            'event' => $event->type,
            'data' => $event->data->object->toArray(),
            'raw_event' => $event,
        ];
    }

    // ─── Helpers ───

    protected function getOrCreateStripeCustomer(string $localUid, string $email): \Stripe\Customer
    {
        $customers = \Stripe\Customer::search([
            'query' => "metadata['local_uid']:'{$localUid}'",
        ]);

        if ($customers->data) {
            return $customers->data[0];
        }

        return \Stripe\Customer::create([
            'email' => $email,
            'metadata' => ['local_uid' => $localUid],
        ]);
    }

    public function getClientSecret(string $customerUid, string $email): string
    {
        $stripeCustomer = $this->getOrCreateStripeCustomer($customerUid, $email);

        $intent = \Stripe\SetupIntent::create([
            'customer' => $stripeCustomer->id,
            'usage' => 'off_session',
            'payment_method_types' => ['card'],
        ]);

        return $intent->client_secret;
    }
}
