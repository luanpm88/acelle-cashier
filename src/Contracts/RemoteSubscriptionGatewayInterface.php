<?php

namespace App\Cashier\Contracts;

use App\Cashier\DTO\RemotePlanDTO;
use App\Cashier\DTO\RemoteSubscriptionDTO;
use App\Cashier\DTO\RemotePaymentMethodDTO;

/**
 * Capability for gateways that manage subscriptions on a remote provider
 * (Stripe, etc.). Read/sync side — write side is via SupportsSubscriptionInterface.
 */
interface RemoteSubscriptionGatewayInterface
{
    /**
     * Fetch all available plans/prices from the remote provider.
     * @return RemotePlanDTO[]
     */
    public function getRemotePlans(): array;

    public function getRemotePlan(string $remotePlanId): RemotePlanDTO;

    public function getRemoteSubscription(string $remoteSubscriptionId): RemoteSubscriptionDTO;

    public function cancelRemoteSubscription(string $remoteSubscriptionId): void;

    public function updateRemoteSubscriptionPlan(
        string $remoteSubscriptionId,
        string $newRemotePlanId
    ): RemoteSubscriptionDTO;

    public function getRemotePaymentMethod(string $remoteSubscriptionId): ?RemotePaymentMethodDTO;

    public function getWebhookSecret(): ?string;

    /**
     * @return array{event: string, data: array}
     */
    public function parseWebhookPayload(string $payload, array $headers): array;
}
