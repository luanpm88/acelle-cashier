<?php

namespace Acelle\Cashier\Services;

use Acelle\Library\Contracts\RemoteSubscriptionGatewayInterface;
use Acelle\Library\DTOs\RemotePlanDTO;
use Acelle\Library\DTOs\RemoteSubscriptionDTO;
use Acelle\Library\DTOs\RemotePaymentMethodDTO;
use Acelle\Library\DTOs\CreateRemoteSubscriptionResult;
use Acelle\Model\Invoice;
use Acelle\Model\Transaction;
use Acelle\Model\PaymentMethod;
use Carbon\Carbon;

class BraintreeSubscriptionGateway implements RemoteSubscriptionGatewayInterface
{
    public const TYPE = 'braintree-subscription';

    protected string $environment;
    protected string $merchantId;
    protected string $publicKey;
    protected string $privateKey;
    protected ?string $webhookSecret;
    protected bool $active = false;
    protected ?\Braintree_Gateway $serviceGateway = null;

    public function __construct(
        string $environment,
        string $merchantId,
        string $publicKey,
        string $privateKey,
        ?string $webhookSecret = null
    ) {
        $this->environment = $environment;
        $this->merchantId = $merchantId;
        $this->publicKey = $publicKey;
        $this->privateKey = $privateKey;
        $this->webhookSecret = $webhookSecret;

        if ($environment && $merchantId && $publicKey && $privateKey) {
            $this->active = true;
            $this->serviceGateway = new \Braintree_Gateway([
                'environment' => $environment,
                'merchantId' => $merchantId,
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ]);
        }
    }

    // ─── PaymentGatewayInterface (base) ───

    public function getCheckoutUrl(Invoice $invoice, string $paymentGatewayId): string
    {
        return action('\Acelle\Cashier\Controllers\BraintreeSubscriptionController@checkout', [
            'invoice_uid' => $invoice->uid,
            'payment_gateway_id' => $paymentGatewayId,
        ]);
    }

    public function supportsAutoBilling(): bool
    {
        return false; // Provider manages billing
    }

    public function autoCharge(Invoice $invoice, PaymentMethod $paymentMethod)
    {
        throw new \Exception('BraintreeSubscription does not support local auto-charge. Billing is managed by Braintree.');
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
        $plans = $this->serviceGateway->plan()->all();

        $result = [];
        foreach ($plans as $plan) {
            $intervalUnit = $this->mapBillingFrequency($plan->billingFrequency);

            $result[] = new RemotePlanDTO(
                id: $plan->id,
                name: $plan->name,
                price: (float) $plan->price,
                currency: strtoupper($plan->currencyIsoCode ?? 'USD'),
                intervalCount: $plan->billingFrequency ?? 1,
                intervalUnit: $intervalUnit,
                status: 'active',
                trialDays: $plan->trialDuration ? $this->trialDurationInDays($plan) : null,
                metadata: [
                    'braintree_plan_id' => $plan->id,
                    'billing_day_of_month' => $plan->billingDayOfMonth ?? null,
                    'number_of_billing_cycles' => $plan->numberOfBillingCycles ?? null,
                ],
            );
        }

        return $result;
    }

    public function getRemotePlan(string $remotePlanId): RemotePlanDTO
    {
        $plans = $this->serviceGateway->plan()->all();

        foreach ($plans as $plan) {
            if ($plan->id === $remotePlanId) {
                $intervalUnit = $this->mapBillingFrequency($plan->billingFrequency);

                return new RemotePlanDTO(
                    id: $plan->id,
                    name: $plan->name,
                    price: (float) $plan->price,
                    currency: strtoupper($plan->currencyIsoCode ?? 'USD'),
                    intervalCount: $plan->billingFrequency ?? 1,
                    intervalUnit: $intervalUnit,
                    status: 'active',
                    trialDays: $plan->trialDuration ? $this->trialDurationInDays($plan) : null,
                    metadata: [
                        'braintree_plan_id' => $plan->id,
                        'billing_day_of_month' => $plan->billingDayOfMonth ?? null,
                    ],
                );
            }
        }

        throw new \Exception("Braintree plan not found: {$remotePlanId}");
    }

    public function getRemoteSubscription(string $remoteSubscriptionId): RemoteSubscriptionDTO
    {
        $sub = $this->serviceGateway->subscription()->find($remoteSubscriptionId);

        $statusMap = [
            \Braintree\Subscription::ACTIVE => 'active',
            \Braintree\Subscription::CANCELED => 'canceled',
            \Braintree\Subscription::EXPIRED => 'canceled',
            \Braintree\Subscription::PAST_DUE => 'past_due',
            \Braintree\Subscription::PENDING => 'incomplete',
        ];

        $latestAmount = null;
        $latestStatus = null;
        if (!empty($sub->transactions)) {
            $latest = $sub->transactions[0];
            $latestAmount = (float) $latest->amount;
            $latestStatus = $latest->status;
        }

        return new RemoteSubscriptionDTO(
            id: $sub->id,
            status: $statusMap[$sub->status] ?? $sub->status,
            remotePlanId: $sub->planId,
            remoteCustomerId: $sub->paymentMethodToken ?? '',
            currentPeriodEnd: $sub->nextBillingDate ? Carbon::instance($sub->nextBillingDate) : null,
            currentPeriodStart: $sub->firstBillingDate ? Carbon::instance($sub->firstBillingDate) : null,
            canceledAt: ($sub->status === \Braintree\Subscription::CANCELED && $sub->updatedAt)
                ? Carbon::instance($sub->updatedAt)
                : null,
            latestInvoiceAmount: $latestAmount,
            latestInvoiceStatus: $latestStatus,
            metadata: ['braintree_subscription' => [
                'id' => $sub->id,
                'plan_id' => $sub->planId,
                'status' => $sub->status,
                'balance' => (string) $sub->balance,
            ]],
        );
    }

    public function createRemoteSubscription(
        Invoice $invoice,
        string $remotePlanId,
        array $checkoutData
    ): CreateRemoteSubscriptionResult {
        try {
            $customer = $invoice->customer;
            $nonce = $checkoutData['payment_method_nonce'];

            $email = $invoice->billing_email ?: $customer->user->email;
            $btCustomer = $this->getOrCreateBraintreeCustomer($customer->uid, $email);

            $paymentMethodResult = $this->serviceGateway->paymentMethod()->create([
                'customerId' => $btCustomer->id,
                'paymentMethodNonce' => $nonce,
                'options' => [
                    'makeDefault' => true,
                ],
            ]);

            if (!$paymentMethodResult->success) {
                return new CreateRemoteSubscriptionResult(
                    success: false,
                    remoteSubscriptionId: null,
                    remoteCustomerId: $btCustomer->id,
                    remotePlanId: $remotePlanId,
                    currentPeriodEnd: null,
                    status: 'failed',
                    error: $paymentMethodResult->message,
                );
            }

            $paymentMethodToken = $paymentMethodResult->paymentMethod->token;

            $subResult = $this->serviceGateway->subscription()->create([
                'paymentMethodToken' => $paymentMethodToken,
                'planId' => $remotePlanId,
                'options' => [
                    'startImmediately' => true,
                ],
            ]);

            if (!$subResult->success) {
                return new CreateRemoteSubscriptionResult(
                    success: false,
                    remoteSubscriptionId: null,
                    remoteCustomerId: $btCustomer->id,
                    remotePlanId: $remotePlanId,
                    currentPeriodEnd: null,
                    status: 'failed',
                    error: $subResult->message,
                );
            }

            $sub = $subResult->subscription;

            $cardInfo = [];
            $pm = $paymentMethodResult->paymentMethod;
            if (isset($pm->cardType)) {
                $cardInfo = [
                    'card_type' => $pm->cardType,
                    'last_4' => $pm->last4,
                ];
            }

            return new CreateRemoteSubscriptionResult(
                success: in_array($sub->status, [\Braintree\Subscription::ACTIVE, \Braintree\Subscription::PENDING]),
                remoteSubscriptionId: $sub->id,
                remoteCustomerId: $btCustomer->id,
                remotePlanId: $remotePlanId,
                currentPeriodEnd: $sub->nextBillingDate ? Carbon::instance($sub->nextBillingDate) : null,
                status: $sub->status,
                paymentMethodData: $cardInfo,
                metadata: ['braintree_subscription_id' => $sub->id],
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
        $sub = $this->serviceGateway->subscription()->find($remoteSubscriptionId);

        $token = $sub->paymentMethodToken ?? null;
        if (!$token) {
            return null;
        }

        try {
            $pm = $this->serviceGateway->paymentMethod()->find($token);
        } catch (\Braintree\Exception\NotFound $e) {
            return null;
        }

        if (isset($pm->cardType)) {
            return new RemotePaymentMethodDTO(
                cardType: $pm->cardType,
                last4: $pm->last4 ?? null,
                expirationDate: $pm->expirationDate ?? null,
                email: null,
                type: 'card',
            );
        }

        // PayPal or other payment method types
        return new RemotePaymentMethodDTO(
            cardType: null,
            last4: null,
            expirationDate: null,
            email: $pm->email ?? null,
            type: 'paypal',
        );
    }

    public function cancelRemoteSubscription(string $remoteSubscriptionId): void
    {
        $this->serviceGateway->subscription()->cancel($remoteSubscriptionId);
    }

    public function updateRemoteSubscriptionPlan(
        string $remoteSubscriptionId,
        string $newRemotePlanId
    ): RemoteSubscriptionDTO {
        $this->serviceGateway->subscription()->update($remoteSubscriptionId, [
            'planId' => $newRemotePlanId,
        ]);

        return $this->getRemoteSubscription($remoteSubscriptionId);
    }

    public function parseWebhookPayload(string $payload, array $headers): array
    {
        $signature = $headers['bt-signature'] ?? ($headers['Bt-Signature'] ?? '');
        $notification = $this->serviceGateway->webhookNotification()->parse($signature, $payload);

        $eventMap = [
            \Braintree\WebhookNotification::SUBSCRIPTION_CHARGED_SUCCESSFULLY => 'invoice.paid',
            \Braintree\WebhookNotification::SUBSCRIPTION_CHARGED_UNSUCCESSFULLY => 'invoice.payment_failed',
            \Braintree\WebhookNotification::SUBSCRIPTION_CANCELED => 'customer.subscription.deleted',
            \Braintree\WebhookNotification::SUBSCRIPTION_EXPIRED => 'customer.subscription.deleted',
            \Braintree\WebhookNotification::SUBSCRIPTION_WENT_ACTIVE => 'customer.subscription.updated',
            \Braintree\WebhookNotification::SUBSCRIPTION_WENT_PAST_DUE => 'customer.subscription.updated',
        ];

        $data = [];
        if ($notification->subscription) {
            $data = [
                'id' => $notification->subscription->id,
                'plan_id' => $notification->subscription->planId,
                'status' => $notification->subscription->status,
            ];
        }

        return [
            'event' => $eventMap[$notification->kind] ?? $notification->kind,
            'data' => $data,
            'raw_event' => $notification,
        ];
    }

    // ─── Helpers ───

    protected function getOrCreateBraintreeCustomer(string $localUid, string $email): \Braintree\Customer
    {
        // First try to find by customer ID (which we set to the local UID on creation)
        try {
            return $this->serviceGateway->customer()->find($localUid);
        } catch (\Braintree\Exception\NotFound $e) {
            // Not found by ID, try searching by email
        }

        try {
            $customers = $this->serviceGateway->customer()->search([
                \Braintree\CustomerSearch::email()->is($email),
            ]);

            foreach ($customers as $customer) {
                return $customer;
            }
        } catch (\Throwable $e) {
            // search failed, create new
        }

        // Create new customer with local UID as the Braintree customer ID
        $result = $this->serviceGateway->customer()->create([
            'id' => $localUid,
            'email' => $email,
        ]);

        if (!$result->success) {
            throw new \Exception('Failed to create Braintree customer: ' . $result->message);
        }

        return $result->customer;
    }

    protected function mapBillingFrequency(?int $frequency): string
    {
        // Braintree billingFrequency is number of months between charges
        return match ($frequency) {
            1 => 'month',
            3 => 'month',  // quarterly
            6 => 'month',  // semi-annual
            12 => 'year',
            default => 'month',
        };
    }

    protected function trialDurationInDays(object $plan): ?int
    {
        if (!$plan->trialDuration) {
            return null;
        }

        return match ($plan->trialDurationUnit) {
            'day' => (int) $plan->trialDuration,
            'month' => (int) $plan->trialDuration * 30,
            default => (int) $plan->trialDuration,
        };
    }

    public function getClientToken(): string
    {
        return $this->serviceGateway->clientToken()->generate();
    }
}
