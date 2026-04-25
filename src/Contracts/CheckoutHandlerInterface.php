<?php

namespace App\Cashier\Contracts;

use App\Cashier\DTO\PaymentIntent;

/**
 * Callback interface for the main app to handle checkout lifecycle events.
 * Cashier calls these methods — main app provides the implementation.
 *
 * Main app must bind an implementation in the service container:
 *   $this->app->bind(CheckoutHandlerInterface::class, MyCheckoutHandler::class);
 *
 * All methods receive a PaymentIntent DTO (not Eloquent Invoice).
 * Main app dereferences invoice + customer + gateway from intent_uid.
 */
interface CheckoutHandlerInterface
{
    /**
     * Cashier asks main app to rehydrate an intent UID into a DTO.
     * MUST authorize ownership: only return intent if it belongs to the authenticated user.
     * Returns null if not found or not authorized.
     */
    public function findIntent(string $intentUid): ?PaymentIntent;

    /**
     * Persist the payment method (card info) returned by gateway, for future auto-billing.
     */
    public function createPaymentMethod(PaymentIntent $intent, array $autoBillingData): PaymentMethodInfoInterface;

    /**
     * Charge succeeded. Mark intent + invoice paid; activate any pending subscription.
     *
     * @param string $remoteRef  Stripe pi_xxx — server-stored, never trust client-supplied later.
     */
    public function onPaymentSuccess(PaymentIntent $intent, PaymentMethodInfoInterface $pm, string $remoteRef): void;

    /**
     * Charge attempt failed (card decline, etc.). Mark intent failed; main app may notify user.
     */
    public function onPaymentFailed(PaymentIntent $intent, PaymentMethodInfoInterface $pm, string $reason): void;

    /**
     * Card requires 3DS challenge. Lock the remote PaymentIntent ID into the intent row
     * so subsequent confirmation reads server-stored ref (not client-supplied).
     *
     * @param string $clientSecret  Stripe pi_xxx_secret_yyy for stripe.confirmCardPayment()
     * @param string $remoteRef     Stripe pi_xxx
     */
    public function onPaymentRequiresAuth(PaymentIntent $intent, string $clientSecret, string $remoteRef): void;

    /**
     * Subscription requires 3DS challenge during creation. Lock the remote sub_xxx + client_secret.
     */
    public function onSubscriptionRequiresAuth(PaymentIntent $intent, string $clientSecret, string $remoteSubscriptionId): void;

    /**
     * Remote subscription successfully active. Persist sub IDs, mark invoice paid, activate local sub.
     *
     * @param array $subscriptionData
     *   - remote_subscription_id: string  (sub_xxx)
     *   - remote_customer_id: string      (cus_xxx)
     *   - current_period_end: int         (unix timestamp)
     *   - payment_method_data: array      (card_type, last_4, ...)
     */
    public function onSubscriptionCreated(PaymentIntent $intent, array $subscriptionData): void;

    /**
     * Offline-only: user clicked "Claim payment". Annotates intent metadata with
     * claimed_at timestamp. Intent stays at status=pending. Admin approves
     * via separate admin UI flow (SubscriptionManagementService::approvePendingInvoice).
     */
    public function onOfflineClaimReceived(PaymentIntent $intent): void;
}
