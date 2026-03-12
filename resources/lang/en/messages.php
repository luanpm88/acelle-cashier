<?php return array(
    'offline' => 'Offline',
    'offline.claim_payment' => 'Claim payment',
    'offline.payment_instruction' => 'Payment Instruction',
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
    'stripe' => 'Stripe',
    'stripe.current_card' => 'Your current card',
    'stripe.pay_with_new_card' => 'Pay with new card',
    'stripe.pay_with_this_card' => 'Pay with this card',
    'stripe.new_card' => 'New Card',
    'stripe.new_card.intro' => 'Fill your card information below and click Pay button.',
    'card.last4' => 'Last 4 number',
    'card.brand' => 'Brand',
    'stripe.click_to_auth' => 'Click <a href=":link">here</a> to authenticate the payment.',
    'stripe.pay' => 'Pay',
    'stripe.secret_key' => 'Secret key',
    'stripe.publishable_key' => 'Publishable key',
    'stripe.intro' => 'Stripe is a technology company based in San Francisco, California.
        Its software allows individuals and businesses to make and receive payments over the Internet.
        Stripe provides the technical, fraud prevention, and banking infrastructure required to
        operate online payment systems.',
    'stripe.checkout.page_title' => 'Checkout',
    'stripe.checkout_with_stripe' => 'Checkout with Stripe',
    'stripe.card_list' => 'Your card information',
    'stripe.use_current_card' => 'Use current card',
    'stripe.change_card' => 'Change card',
    'stripe.connected' => 'You connected to Stripe successfully',
    'stripe.click_button_to_connect' => 'Click button below to add payment method',
    'stripe.add_card' => 'Add card',
    'stripe.return_back' => 'Return back',
    'stripe.checkout.processing_payment.intro' => 'This process is automatic. Please do not close this browser/tab or change page.
        <br> Your browser will redirect to next page shortly.',
    'stripe.checkout.processing_payment' => 'Processing your payment... please wait!',
    'pay_invoice' => 'Pay invoice',
    'braintree' => 'Braintree',
    'braintree.environment' => 'Environment',
    'braintree.merchant_id' => 'Merchant ID',
    'braintree.public_key' => 'Public key',
    'braintree.private_key' => 'Private key',
    'braintree.intro' => 'Braintree is a full-stack payments platform that makes it easy to accept online payments. You will need to sign up for a Braintree account, obtain your integration ID and keys and set it up below',
    'braintree.checkout.processing_payment.intro' => 'This process is automatic. Please do not close this browser/tab or change page.
        <br> Your browser will redirect to next page shortly.',
    'braintree.checkout.processing_payment' => 'Processing your payment... please wait!',
    'braintree.pending.page_title' => 'Transaction is pending',
    'braintree.checkout.page_title' => 'Checkout',
    'braintree.checkout_with_braintree' => 'Checkout with Braintree',
    'braintree.click_bellow_to_pay' => 'You are checking out <strong>:plan</strong> with Braintree with <strong>:price</strong>.
            Find your card information below and click Pay button to proceed with the payment.',
    'braintree.current_card' => 'Your current card',
    'braintree.pay' => 'Pay',
    'braintree.card_list' => 'Your card information',
    'braintree.pay_with_new_card' => 'Pay with new card',
    'braintree.pay_with_this_card' => 'Pay with this card',
    'braintree.use_new_card' => 'Use new card',
    'braintree.new_card' => 'New card',
    'braintree.use_this_card' => 'Use this card',
    'paystack' => 'Paystack',
    'paystack.secret_key' => 'Secret key',
    'paystack.public_key' => 'Public key',
    'paystack.intro' => 'Start Accepting Payments Instantly with Razorpay\'s Free Payment Gateway.
        Supports Netbanking, Credit, Debit Cards etc',
    'paystack.checkout_with_paystack' => 'Pay with Paystack',
    'paystack.click_bellow_to_pay' => 'You are paying an invoice with amount <strong>:price</strong>.
        Please click the button bellow to proceed with the payment information.',
    'paystack.pay' => 'Pay Now',
    'paystack.checkout.processing_payment.intro' => 'This process is automatic. Please do not close this browser/tab or change page.
        <br> Your browser will redirect to next page shortly.',
    'paystack.checkout.processing_payment' => 'Processing your payment... please wait!',
    'paypal' => 'Paypal',
    'paypal.user.description' => 'Pay for your subscription & services using your PayPal account.',
    'paypal.environment' => 'Environment',
    'paypal.client_id' => 'Client ID',
    'paypal.secret' => 'Secret',
    'paypal.intro' => 'PayPal is the fast/safe way to send money, make an online payment, receive money or set up a merchant account.',
    'paypal.checkout.processing_payment.intro' => 'This process is automatic. Please do not close this browser/tab or change page.
        <br> Your browser will redirect to next page shortly.',
    'paypal.checkout.processing_payment' => 'Processing your payment... please wait!',
    'paypal.checkout.intro' => 'You are paying an invoice with amount <strong>:price</strong>.
        Click on the button below to finish your payment with PayPal before using plan.',
    'razorpay' => 'Razorpay',
    'razorpay.key_id' => 'Key ID',
    'razorpay.key_secret' => 'Key Secret',
    'razorpay.intro' => 'Start Accepting Payments Instantly with Razorpay\'s Free Payment Gateway. Supports Netbanking, Credit, Debit Cards etc',
    'razorpay.checkout.processing_payment.intro' => 'This process is automatic. Please do not close this browser/tab or change page.
        <br> Your browser will redirect to next page shortly.',
    'razorpay.checkout.processing_payment' => 'Processing your payment... please wait!',
    'razorpay.checkout.intro' => 'You are paying an invoice with amount <strong>:price</strong>.
        Click on the button below to finish your payment with PayPal before using plan.',
    'razorpay.pay_with_razorpay' => 'Pay with Razorpay',
    'payment.options' => 'Payment Options',
    'go_back' => 'Go back',
    'stripe.no_card' => 'Fill your card information below and click Pay button.',
    'gateway.updated' => 'Gateway settings were updated',
    'save_and_enable' => 'Save & Enable',
    'connect' => 'Connect',
    'cancel' => 'Cancel',
    'braintree.description' => 'Receive payments from Credit / Debit card to your Braintree account',
    'offline.description' => 'Receive payments outside of the application',
    'paypal.description' => 'PayPal is the fast/safe way to send money, make an online payment, receive money or set up a merchant account',
    'paystack.description' => 'Receive payments from Credit / Debit card to your Paystack account',
    'razorpay.description' => 'Start Accepting Payments Instantly with Razorpay\'s
        Free Payment Gateway. Supports Netbanking, Credit, Debit Cards etc',
    'stripe.description' => 'Receive payments from Credit / Debit card to your Stripe account',
    'braintree.short_description' => 'Pay for your subscription & services using your debit
        or credit cards. Auto billing is supported',
    'offline.short_description' => 'Pay for your subscription & services outside of
        the application, following the instructions provided during checkout',
    'paypal.short_description' => 'Pay for your subscription & services using your PayPal account.',
    'paystack.short_description' => 'Pay for your subscription & services using your debit
        or credit cards. Auto billing is supported',
    'razorpay.short_description' => 'Pay for your subscription & services using your debit
        or credit cards. Auto billing is not supported',
    'stripe.short_description' => 'Pay for your subscription & services using your debit
        or credit cards. Auto billing is supported',
    'braintree.click_to_auth' => 'Click <a href=":link">here</a> to authenticate the payment.',
    'quantity' => 'Quantity',
    'total_due' => 'Total due',
    'pay_invoice' => 'Pay Invoice',
    'stripe.checkout.page_title' => 'Checkout',
    'stripe.new_card' => 'New Card',
    'stripe.new_card.intro2' => 'Please fill in your card information below to complete the payment securely. Ensure all details are accurate to avoid transaction issues.',
    'stripe.pay' => 'Pay',
    'secured_transaction' => 'Secured Transaction',
    'total_amount' => 'Total amount',
    'items' => 'Items',
    'subtotal' => 'Subtotal',
    'payment_transaction_fee' => 'Payment Transaction Fee',
    'activate_account_after_subscribing' => 'Activate account after subscribing to the plan',
    'total_due' => 'Total due',
    'powered_by' => 'Powered by',
    'privacy_terms' => 'Privacy & terms',
    'offline.payment_instructions' => 'Please follow the instructions below to complete your payment:',

    // ─── Stripe Subscription ───
    'stripe_subscription.subscribing_to' => 'Subscribing to',
    'stripe_subscription.processing' => 'Processing...',
    'stripe_subscription.completing' => 'Completing subscription...',
    'stripe_subscription.payment_failed' => 'Payment failed',
    'stripe_subscription.invoice_not_found' => 'Invoice not found.',
    'stripe_subscription.gateway_not_found' => 'Payment gateway not found.',
    'stripe_subscription.no_subscription' => 'No subscription found for this invoice.',

    // ─── Braintree Subscription ───
    'braintree_subscription.checkout_title' => 'Checkout with Braintree',
    'braintree_subscription.subscribing_to' => 'Subscribing to',
    'braintree_subscription.processing' => 'Processing...',
    'braintree_subscription.payment_failed' => 'Payment failed',
    'braintree_subscription.invoice_not_found' => 'Invoice not found.',
    'braintree_subscription.gateway_not_found' => 'Payment gateway not found.',
    'braintree_subscription.no_subscription' => 'No subscription found for this invoice.',

    // ─── Webhook ───
    'webhook.invalid_signature' => 'Invalid signature',
    'webhook.invalid_payload' => 'Invalid payload',

    // Stripe Subscription
    'stripe_subscription' => 'Stripe Subscription',
    'stripe_subscription.publishable_key' => 'Publishable key',
    'stripe_subscription.secret_key' => 'Secret key',
    'stripe_subscription.webhook_secret' => 'Webhook secret',
    'stripe_subscription.description' => 'Recurring billing managed by Stripe Subscriptions',
);
