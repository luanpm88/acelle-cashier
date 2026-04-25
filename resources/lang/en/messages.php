<?php return array(
    // ─── Common ───
    'pay_invoice' => 'Pay invoice',
    'powered_by' => 'Powered by',
    'privacy_terms' => 'Privacy & terms',
    'go_back' => 'Go back',
    'cancel' => 'Cancel',
    'connect' => 'Connect',
    'save_and_enable' => 'Save & Enable',
    'gateway.updated' => 'Gateway settings were updated',
    'secured_transaction' => 'Secured Transaction',
    'total_amount' => 'Total amount',
    'total_due' => 'Total due',
    'items' => 'Items',
    'subtotal' => 'Subtotal',
    'quantity' => 'Quantity',
    'payment_transaction_fee' => 'Payment Transaction Fee',
    'activate_account_after_subscribing' => 'Activate account after subscribing to the plan',
    'card.last4' => 'Last 4 number',
    'card.brand' => 'Brand',
    'payment.options' => 'Payment Options',

    // ─── Offline ───
    'offline' => 'Offline',
    'offline.description' => 'Receive payments outside of the application',
    'offline.short_description' => 'Pay for your subscription & services outside of the application, following the instructions provided during checkout',
    'offline.claim_payment' => 'Claim payment',
    'offline.payment_instruction' => 'Payment Instruction',
    'offline.payment_instructions' => 'Please follow the instructions below to complete your payment:',
    'offline.payment_instruction.default' => '
        <p>Please make a deposit to our bank account at:</p>

        <p><strong>FIRST CENTURY BANK USA</strong><br>
        Routing (ABA): 061120084<br>
        Account number: 4013000067378<br>
        Beneficiary name: Jolie Kennedy<br>
        </p>

        <p>---</p>

        <p><strong>CITIBANK UK</strong><br>
        SWIFT CODE: CIBLBDDHXXX<br>
        Account number: 9000110067378<br>
        Beneficiary name: Jolie Kennedy<br>
        </p>

        <p class="text-danger" style="color: red">* Above is sample payment information, you can change it in your payment gateway setting page</p>
    ',
    'offline.amount_due' => 'Amount due',
    'offline.claim_received' => 'Your payment claim has been received. An administrator will review and approve it shortly.',
    'offline.intent_not_found' => 'Payment intent not found.',

    // ─── Stripe ───
    'stripe' => 'Stripe',
    'stripe.description' => 'Receive payments from Credit / Debit card to your Stripe account',
    'stripe.short_description' => 'Pay for your subscription & services using your debit or credit cards. Auto billing is supported',
    'stripe.intro' => 'Stripe is a technology company based in San Francisco, California. Its software allows individuals and businesses to make and receive payments over the Internet. Stripe provides the technical, fraud prevention, and banking infrastructure required to operate online payment systems.',
    'stripe.publishable_key' => 'Publishable key',
    'stripe.secret_key' => 'Secret key',
    'stripe.pay' => 'Pay',
    'stripe.new_card' => 'New Card',
    'stripe.new_card.intro' => 'Fill your card information below and click Pay button.',
    'stripe.new_card.intro2' => 'Please fill in your card information below to complete the payment securely. Ensure all details are accurate to avoid transaction issues.',
    'stripe.no_card' => 'Fill your card information below and click Pay button.',
    'stripe.current_card' => 'Your current card',
    'stripe.card_list' => 'Your card information',
    'stripe.use_current_card' => 'Use current card',
    'stripe.change_card' => 'Change card',
    'stripe.add_card' => 'Add card',
    'stripe.pay_with_new_card' => 'Pay with new card',
    'stripe.pay_with_this_card' => 'Pay with this card',
    'stripe.connected' => 'You connected to Stripe successfully',
    'stripe.click_button_to_connect' => 'Click button below to add payment method',
    'stripe.click_to_auth' => 'Click <a href=":link">here</a> to authenticate the payment.',
    'stripe.return_back' => 'Return back',
    'stripe.checkout.page_title' => 'Checkout',
    'stripe.checkout_with_stripe' => 'Checkout with Stripe',
    'stripe.checkout.processing_payment' => 'Processing your payment... please wait!',
    'stripe.checkout.processing_payment.intro' => 'This process is automatic. Please do not close this browser/tab or change page. <br> Your browser will redirect to next page shortly.',
    'stripe.intent_not_found' => 'Payment intent not found.',

    // ─── Stripe Subscription ───
    'stripe_subscription' => 'Stripe Subscription',
    'stripe_subscription.description' => 'Recurring billing managed by Stripe Subscriptions',
    'stripe_subscription.publishable_key' => 'Publishable key',
    'stripe_subscription.secret_key' => 'Secret key',
    'stripe_subscription.webhook_secret' => 'Webhook secret',
    'stripe_subscription.subscribing_to' => 'Subscribing to',
    'stripe_subscription.processing' => 'Processing...',
    'stripe_subscription.completing' => 'Completing subscription...',
    'stripe_subscription.payment_failed' => 'Payment failed',
    'stripe_subscription.invoice_not_found' => 'Invoice not found.',
    'stripe_subscription.intent_not_found' => 'Payment intent not found.',
    'stripe_subscription.gateway_not_found' => 'Payment gateway not found.',
    'stripe_subscription.no_subscription' => 'No subscription found for this invoice.',

    // ─── Webhook ───
    'webhook.invalid_signature' => 'Invalid signature',
    'webhook.invalid_payload' => 'Invalid payload',
);
