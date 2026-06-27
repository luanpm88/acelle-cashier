<?php

namespace App\Cashier\Contracts;

use App\Cashier\DTO\PaymentIntent;
use App\Cashier\DTO\PaymentMethodDTO;
use App\Cashier\DTO\RemoteSubscriptionDTO;
use App\Cashier\DTO\RemoteBillingDetailsDTO;

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
     * Persist the captured card (a typed PaymentMethodDTO) for future auto-billing. The
     * gateway sets PaymentMethodDTO.autoCharge to flag whether it's off-session chargeable.
     */
    public function createPaymentMethod(PaymentIntent $intent, PaymentMethodDTO $card): void;

    /**
     * Charge succeeded. Mark intent + invoice paid; activate any pending subscription.
     *
     * @param string $remoteRef  Stripe pi_xxx — server-stored, never trust client-supplied later.
     */
    public function onPaymentSuccess(PaymentIntent $intent, string $remoteRef): void;

    /**
     * Charge attempt failed (card decline, etc.). Mark intent failed; main app may notify user.
     */
    public function onPaymentFailed(PaymentIntent $intent, string $reason): void;

    /**
     * Card requires 3DS challenge. Lock the remote PaymentIntent ID into the intent row
     * so subsequent confirmation reads server-stored ref (not client-supplied).
     *
     * @param string $clientSecret  Stripe pi_xxx_secret_yyy for stripe.confirmCardPayment()
     * @param string $remoteRef     Stripe pi_xxx
     */
    public function onPaymentRequiresAuth(PaymentIntent $intent, string $clientSecret, string $remoteRef): void;

    // NOTE: onSubscriptionRequiresAuth (the on-site 3DS challenge for imperative
    // createSubscription) was removed with Lane A — no gateway drives that path anymore.

    /**
     * Completion callback for an INTENT-BASED redirect subscription gateway: the
     * provider hosted its own payment page, created the recurring subscription, and
     * reported success (via webhook, poll, or its own controller — e.g. 2C2P RPP). The
     * app owns a local PaymentIntent for this flow and settles it here, then activates
     * the local subscription via SubscriptionManagementService::handleRemoteSubscriptionCreated.
     *
     * NOT the imperative on-site creation (that was removed).
     *
     * @param RemoteSubscriptionDTO $subscription   the created remote subscription (id, status, customer, period).
     * @param ?PaymentMethodDTO     $card           the captured card to persist (null if the vendor owns the vault).
     */
    public function onSubscriptionCreated(PaymentIntent $intent, RemoteSubscriptionDTO $subscription, ?PaymentMethodDTO $card = null, ?RemoteBillingDetailsDTO $billing = null): void;

    /**
     * Offline-only: user clicked "Claim payment". Annotates intent metadata with
     * claimed_at timestamp. Intent stays at status=pending. Admin approves
     * via separate admin UI flow (SubscriptionManagementService::approvePendingInvoice).
     */
    public function onOfflineClaimReceived(PaymentIntent $intent): void;
}
