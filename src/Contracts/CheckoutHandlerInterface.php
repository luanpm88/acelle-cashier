<?php

namespace App\Cashier\Contracts;

/**
 * Callback interface for the main app to handle checkout lifecycle events.
 * Library calls these methods — main app provides the implementation.
 *
 * Main app must bind an implementation in the service container:
 *   $this->app->bind(CheckoutHandlerInterface::class, MyCheckoutHandler::class);
 */
interface CheckoutHandlerInterface
{
    /**
     * Save a payment method to the database for future auto-billing.
     *
     * @param  mixed  $invoice           The invoice (used to find the customer)
     * @param  string $paymentGatewayId  UID of the payment gateway (for DB association)
     * @param  array  $autoBillingData   Card + customer info from the gateway
     * @return PaymentMethodInfoInterface
     */
    public function createPaymentMethod($invoice, string $paymentGatewayId, array $autoBillingData): PaymentMethodInfoInterface;

    /**
     * Called when payment succeeds (charge completed or invoice is free).
     */
    public function onPaymentSuccess($invoice, PaymentMethodInfoInterface $paymentMethodInfo);

    /**
     * Called when payment fails (e.g. card declined, 3D Secure required).
     */
    public function onPaymentFailed($invoice, PaymentMethodInfoInterface $paymentMethodInfo, string $reason);

    /**
     * Called when a remote subscription is successfully created or confirmed (3DS).
     * Main app should: save remote subscription data, create payment method, mark invoice as paid.
     *
     * @param  mixed  $invoice
     * @param  string $paymentGatewayId  UID of the payment gateway
     * @param  array  $subscriptionData  Contains: remote_subscription_id, remote_customer_id, status,
     *                                   current_period_end, payment_method_data (card_type, last_4, etc.)
     */
    public function onRemoteSubscriptionCreated($invoice, string $paymentGatewayId, array $subscriptionData);
}
