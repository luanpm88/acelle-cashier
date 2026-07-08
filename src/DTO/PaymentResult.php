<?php

namespace App\Cashier\DTO;

/**
 * Return value of SupportsAutoChargeInterface::autoCharge().
 *
 * Gateway services are PURE — they call the provider, map the response into one of
 * four outcomes, and return. They do NOT mutate DB or invoke handler callbacks.
 * The controller pattern-matches on $status and dispatches the appropriate handler.
 *
 * SUBSCRIPTION_CREATED is the outcome when the intent carried a SubscriptionSpec and the
 * gateway created a PROVIDER-managed subscription off-session (saved card, no redirect) —
 * a catalog gateway's autoCharge does this instead of a one-off charge. It carries the
 * {@see RemoteSubscriptionDTO} so the orchestrator can route to the SAME
 * CheckoutHandlerInterface::onSubscriptionCreated seam the hosted-return + webhook paths use
 * (the host — not the gateway — owns activation / setRemoteSubscription).
 */
class PaymentResult
{
    public const STATUS_SUCCESS              = 'success';
    public const STATUS_FAILED               = 'failed';
    public const STATUS_REQUIRES_ACTION      = 'requires_action';
    public const STATUS_SUBSCRIPTION_CREATED = 'subscription_created';

    private function __construct(
        public readonly string $status,
        public readonly ?string $remoteReferenceId = null, // Stripe pi_xxx (or sub_xxx for SUBSCRIPTION_CREATED)
        public readonly ?string $clientSecret = null,      // for 3DS challenge
        public readonly ?string $error = null,
        public readonly array $metadata = [],
        // Set ONLY for STATUS_SUBSCRIPTION_CREATED — the provider subscription the host links via
        // onSubscriptionCreated. $card is the captured card to persist, or null if it is already
        // saved (off-session reuse) / the vendor owns the vault.
        public readonly ?RemoteSubscriptionDTO $subscription = null,
        public readonly ?PaymentMethodDTO $card = null,
    ) {}

    public static function success(string $remoteRef, array $meta = []): self
    {
        return new self(
            status: self::STATUS_SUCCESS,
            remoteReferenceId: $remoteRef,
            metadata: $meta,
        );
    }

    public static function failed(string $error, ?string $remoteRef = null): self
    {
        return new self(
            status: self::STATUS_FAILED,
            remoteReferenceId: $remoteRef,
            error: $error,
        );
    }

    public static function requiresAuth(string $clientSecret, string $remoteRef): self
    {
        return new self(
            status: self::STATUS_REQUIRES_ACTION,
            remoteReferenceId: $remoteRef,
            clientSecret: $clientSecret,
        );
    }

    /**
     * The intent carried a SubscriptionSpec and the gateway created a provider subscription
     * off-session. The host links it via CheckoutHandlerInterface::onSubscriptionCreated.
     */
    public static function subscriptionCreated(RemoteSubscriptionDTO $subscription, ?PaymentMethodDTO $card = null): self
    {
        return new self(
            status: self::STATUS_SUBSCRIPTION_CREATED,
            remoteReferenceId: $subscription->id,
            subscription: $subscription,
            card: $card,
        );
    }
}
