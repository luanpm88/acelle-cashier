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
    /**
     * An app-initiated plan change the provider was HOLDING has now been paid for — apply it.
     *
     * The gateway driver must not reach into the app's own services to do this. It knows one fact
     * ("the provider now reports this subscription on plan X") and hands it over; what that means
     * for entitlement, credits and the audit trail is the app's business, decided in one place.
     *
     * Implementations MUST treat the plan flip and its credit reconciliation as a single unit.
     * They were separate once, in two different webhook handlers, and a 3-D Secure upgrade could
     * land the flip in one and the reconcile in the other — the buyer was billed for the new plan
     * and kept the old plan's allowance for the rest of the cycle.
     *
     * MUST be idempotent: every observer (each webhook delivery, the poller, an on-session
     * confirmation) calls this, and only one of them can be first.
     *
     * @param  string  $subscriptionUid  the app's own subscription handle — no host model crosses
     *                                   this boundary, so the driver stays free of app types.
     * @return bool  true only when THIS call performed the flip; false when there was nothing
     *               pending, the provider has not applied it yet, or someone else got there first.
     */
    public function onRemotePlanChangeConfirmed(string $subscriptionUid, RemoteSubscriptionDTO $remoteSubscription): bool;

    /**
     * The provider reports this subscription live (active/trialing) while the app still has it NEW.
     *
     * @param  string  $subscriptionUid  the app's own subscription handle
     * @param  string  $gatewayUid       the gateway that reported it
     */
    public function onRemoteSubscriptionActivated(string $subscriptionUid, string $gatewayUid): void;

    /** The provider cancelled this subscription — bring the app's copy into line. */
    public function onRemoteSubscriptionCancelled(string $subscriptionUid): void;

    /**
     * Reconcile a hosted-checkout session the app is tracking, and report what it settled.
     *
     * @param  string  $intentUid  the app's own payment-intent handle
     * @return string  the app's own outcome word; 'completed' means the invoice is settled
     */
    public function reconcileHostedCheckout(string $intentUid): string;

    /**
     * The provider could not collect on this subscription's invoice.
     *
     * The driver reports the event and stops: it does not decide what a failed collection MEANS —
     * whether entitlement is cut, whether the buyer is emailed, how it is surfaced for review. That
     * is the app's policy, and it must have exactly one home, because both observers reach it (the
     * invoice.payment_failed webhook, and the manual sync noticing the subscription went past_due).
     * Two implementations of "what a failed renewal means" is how they drift apart.
     *
     * MUST be idempotent: a provider retries collection several times, each retry raising the event
     * again, and an admin may sync in between.
     *
     * @param  string       $subscriptionUid  the app's own subscription handle
     * @param  string|null  $remoteInvoiceId  the provider's invoice, when the event names one — the
     *                                        app uses it to ask the gateway WHY it failed rather
     *                                        than having the driver guess
     * @param  string|null  $reason           the provider's own message, when it gave one
     */
    public function onRemotePaymentFailed(string $subscriptionUid, ?string $remoteInvoiceId = null, ?string $reason = null): void;

    public function onOfflineClaimReceived(PaymentIntent $intent): void;
}
