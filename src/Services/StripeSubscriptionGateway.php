<?php

namespace App\Cashier\Services;

use App\Cashier\Contracts\IntentGatewayInterface;
use App\Cashier\Contracts\SupportsSubscriptionInterface;
use App\Cashier\Contracts\RemoteSubscriptionGatewayInterface;
use App\Cashier\DTO\PaymentIntent;
use App\Cashier\DTO\SubscriptionResult;
use App\Cashier\DTO\RemotePlanDTO;
use App\Cashier\DTO\RemoteSubscriptionDTO;
use App\Cashier\DTO\RemotePaymentMethodDTO;
use Carbon\Carbon;

/**
 * Stripe Subscription gateway (Category B — provider manages billing).
 *
 * Implements:
 * - IntentGatewayInterface  → consumes PaymentIntent at checkout
 * - SupportsSubscriptionInterface  → creates remote subscription, returns SubscriptionResult
 * - RemoteSubscriptionGatewayInterface  → read/sync side (plans, webhook, etc.)
 *
 * Pure: no DB writes, no handler callbacks. Controller orchestrates side-effects.
 */
class StripeSubscriptionGateway implements
    IntentGatewayInterface,
    SupportsSubscriptionInterface,
    RemoteSubscriptionGatewayInterface
{
    public const TYPE = 'stripe-subscription';

    protected string $publishableKey;
    protected string $secretKey;
    protected ?string $webhookSecret;
    protected bool $active = false;

    public function __construct(string $publishableKey, string $secretKey, ?string $webhookSecret = null)
    {
        $this->publishableKey = $publishableKey;
        $this->secretKey      = $secretKey;
        $this->webhookSecret  = $webhookSecret;

        if ($publishableKey && $secretKey) {
            $this->active = true;
            \Stripe\Stripe::setApiKey($this->secretKey);
            \Stripe\Stripe::setApiVersion('2023-10-16');
        }
    }

    public function getType(): string
    {
        return self::TYPE;
    }

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

    /**
     * IntentGatewayInterface — checkout URL the user is redirected to.
     */
    public function getCheckoutUrl(PaymentIntent $intent, string $returnUrl): string
    {
        return action('\App\Cashier\Controllers\StripeSubscriptionController@checkout', [
            'intent_uid' => $intent->uid,
        ]) . '?return_url=' . urlencode($returnUrl);
    }

    /**
     * SupportsSubscriptionInterface — create the remote subscription. PURE.
     */
    public function createSubscription(PaymentIntent $intent, array $pmData): SubscriptionResult
    {
        if (!$intent->subscription) {
            return SubscriptionResult::failed('PaymentIntent missing SubscriptionSpec for createSubscription()');
        }

        $customerId      = $pmData['stripe_customer'] ?? null;
        $paymentMethodId = $pmData['stripe_payment_method'] ?? null;

        if (!$customerId || !$paymentMethodId) {
            return SubscriptionResult::failed('Missing stripe_customer or stripe_payment_method in pmData');
        }

        try {
            // Attach PM to customer if not already
            $pm = \Stripe\PaymentMethod::retrieve($paymentMethodId);
            if (!$pm->customer) {
                $pm->attach(['customer' => $customerId]);
            }

            \Stripe\Customer::update($customerId, [
                'invoice_settings' => ['default_payment_method' => $paymentMethodId],
            ]);

            $sub = \Stripe\Subscription::create([
                'customer'               => $customerId,
                'items'                  => [['price' => $intent->subscription->remotePlanId]],
                'default_payment_method' => $paymentMethodId,
                'payment_behavior'       => 'default_incomplete',
                'expand'                 => ['latest_invoice.payment_intent'],
                'metadata'               => [
                    'intent_uid'        => $intent->uid,
                    'local_payer_uid'   => $intent->payer->uid,
                ],
            ]);

            $cardInfo = $this->extractCardInfo($pm);

            // Active or trialing immediately — happy path
            if (in_array($sub->status, ['active', 'trialing'])) {
                return SubscriptionResult::active(
                    subId: $sub->id,
                    customerId: $customerId,
                    periodEnd: (int) $sub->current_period_end,
                    pmData: $cardInfo,
                );
            }

            // Incomplete because card needs 3DS
            if ($sub->status === 'incomplete') {
                $pi = $sub->latest_invoice->payment_intent ?? null;

                if ($pi && in_array($pi->status, ['requires_action', 'requires_confirmation'])) {
                    return SubscriptionResult::requiresAuth(
                        subId: $sub->id,
                        clientSecret: $pi->client_secret,
                        pmData: $cardInfo,
                    );
                }

                if ($pi && $pi->status === 'succeeded') {
                    // Race: PI succeeded but sub status hasn't transitioned yet — re-fetch
                    $sub = \Stripe\Subscription::retrieve($sub->id);
                    if (in_array($sub->status, ['active', 'trialing'])) {
                        return SubscriptionResult::active(
                            subId: $sub->id,
                            customerId: $customerId,
                            periodEnd: (int) $sub->current_period_end,
                            pmData: $cardInfo,
                        );
                    }
                }
            }

            return SubscriptionResult::failed("Unexpected subscription status: {$sub->status}");
        } catch (\Stripe\Exception\CardException $e) {
            return SubscriptionResult::failed($e->getError()->message ?? $e->getMessage());
        } catch (\Throwable $e) {
            return SubscriptionResult::failed($e->getMessage());
        }
    }

    private function extractCardInfo(\Stripe\PaymentMethod $pm): array
    {
        if ($pm->type !== 'card' || !$pm->card) {
            return [];
        }
        return [
            'card_type' => ucfirst($pm->card->brand),
            'last_4'    => $pm->card->last4,
            'exp_month' => (int) $pm->card->exp_month,
            'exp_year'  => (int) $pm->card->exp_year,
        ];
    }

    // ─── RemoteSubscriptionGatewayInterface — read/sync ───

    public function getRemotePlans(): array
    {
        $prices = \Stripe\Price::all([
            'active' => true,
            'type'   => 'recurring',
            'limit'  => 100,
            'expand' => ['data.product'],
        ]);

        return array_map(fn($price) => $this->mapPriceToPlanDto($price), $prices->data);
    }

    public function getRemotePlan(string $remotePlanId): RemotePlanDTO
    {
        $price = \Stripe\Price::retrieve([
            'id'     => $remotePlanId,
            'expand' => ['product'],
        ]);
        return $this->mapPriceToPlanDto($price);
    }

    private function mapPriceToPlanDto(\Stripe\Price $price): RemotePlanDTO
    {
        $product = $price->product;
        $trialDays = $price->recurring->trial_period_days ?? null;

        return new RemotePlanDTO(
            id:            $price->id,
            name:          is_object($product) ? $product->name : $price->id,
            price:         $price->unit_amount / 100,
            currency:      strtoupper($price->currency),
            intervalCount: $price->recurring->interval_count,
            intervalUnit:  $price->recurring->interval,
            status:        $price->active ? 'active' : 'inactive',
            trialDays:     $trialDays ? (int) $trialDays : null,
            metadata: [
                'stripe_product_id' => is_object($product) ? $product->id : $price->product,
                'stripe_price_id'   => $price->id,
            ],
        );
    }

    public function getRemoteSubscription(string $remoteSubscriptionId): RemoteSubscriptionDTO
    {
        $sub = \Stripe\Subscription::retrieve([
            'id'     => $remoteSubscriptionId,
            'expand' => ['latest_invoice'],
        ]);
        return $this->mapSubscriptionToDto($sub);
    }

    public function getRemoteSubscriptions(?string $startingAfter = null, int $limit = 100): array
    {
        $params = [
            'limit'  => max(1, min($limit, 100)),
            'status' => 'all',
            'expand' => ['data.latest_invoice'],
        ];
        if ($startingAfter) {
            $params['starting_after'] = $startingAfter;
        }

        $page = \Stripe\Subscription::all($params);

        $data = array_map(fn($sub) => $this->mapSubscriptionToDto($sub), $page->data);

        return [
            'data'        => $data,
            'has_more'    => (bool) $page->has_more,
            'next_cursor' => $page->has_more && count($data) > 0 ? end($data)->id : null,
        ];
    }

    private function mapSubscriptionToDto(\Stripe\Subscription $sub): RemoteSubscriptionDTO
    {
        $latestAmount = null;
        $latestStatus = null;
        if ($sub->latest_invoice && is_object($sub->latest_invoice)) {
            $latestAmount = $sub->latest_invoice->amount_paid / 100;
            $latestStatus = $sub->latest_invoice->status;
        }

        return new RemoteSubscriptionDTO(
            id:                  $sub->id,
            status:              $sub->status,
            remotePlanId:        $sub->items->data[0]->price->id ?? '',
            remoteCustomerId:    is_string($sub->customer) ? $sub->customer : ($sub->customer->id ?? null),
            currentPeriodEnd:    Carbon::createFromTimestamp($sub->current_period_end),
            currentPeriodStart:  Carbon::createFromTimestamp($sub->current_period_start),
            canceledAt:          $sub->canceled_at ? Carbon::createFromTimestamp($sub->canceled_at) : null,
            latestInvoiceAmount: $latestAmount,
            latestInvoiceStatus: $latestStatus,
            metadata:            ['stripe_subscription' => $sub->toArray()],
        );
    }

    public function getRemotePaymentMethod(string $remoteSubscriptionId): ?RemotePaymentMethodDTO
    {
        $sub = \Stripe\Subscription::retrieve($remoteSubscriptionId);

        $paymentMethodId = $sub->default_payment_method;
        if (is_object($paymentMethodId) && isset($paymentMethodId->id)) {
            $paymentMethodId = $paymentMethodId->id;
        }
        if (!$paymentMethodId || !is_string($paymentMethodId)) {
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
                cardType:       ucfirst($pm->card->brand),
                last4:          $pm->card->last4,
                expirationDate: $pm->card->exp_month . '/' . $pm->card->exp_year,
                email:          null,
                type:           'card',
            );
        }

        return new RemotePaymentMethodDTO(
            cardType:       null,
            last4:          null,
            expirationDate: null,
            email:          null,
            type:           $pm->type,
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
                'id'    => $sub->items->data[0]->id,
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
            'event'     => $event->type,
            'data'      => $event->data->object->toArray(),
            'raw_event' => $event,
        ];
    }

    // ─── Customer + SetupIntent helpers (used by controller / sync flows) ───

    public function getStripeCustomer(string $payerUid): ?\Stripe\Customer
    {
        $customers = \Stripe\Customer::search([
            'query' => "metadata['payer_uid']:'{$payerUid}'",
        ]);

        return $customers->data[0] ?? null;
    }

    public function createStripeCustomer(string $payerUid, string $name = ''): \Stripe\Customer
    {
        $params = ['metadata' => ['payer_uid' => $payerUid]];
        if ($name) {
            $params['name'] = $name;
        }
        return \Stripe\Customer::create($params);
    }

    // getSetupIntentSecret() removed.
    //
    // Was used by the legacy 2-popup flow:
    //   confirmCardSetup(setup_secret)   → 3DS popup #1 (attach card)
    //   confirmCardPayment(invoice_pi)   → 3DS popup #2 (charge first invoice)
    //
    // Replaced by single-popup `default_incomplete` pattern:
    //   stripe.createPaymentMethod(card) → tokenize, NO 3DS
    //   confirmCardPayment(invoice_pi)   → 3DS popup #1 (atomic: attach + charge + activate)

    /**
     * Display helper for payment_methods table. Not part of any interface.
     */
    public function getMethodTitle($billingData): string
    {
        return $billingData['card_type'] ?? 'Card';
    }

    public function getMethodInfo($billingData): string
    {
        return '*** *** *** ' . ($billingData['last_4'] ?? '****');
    }
}
