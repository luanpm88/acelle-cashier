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
    'coinpayments.receive_currency' => 'Receive Currency Code',
    'coinpayments' => 'Coin-Payments',
    'coinpayments.list_intro' => 'Receive payment from a cryptocurrency like Bitcoin, Monero, ZCash, etc.',
    'coinpayments.wording' => 'You can accept payments from many cryptocurrencies like Bitcoin, Monero, ZCash, etc. You will need to sign up for a Coin-Payments account, obtain your integration ID and keys and set it up below',
    'coinpayments.merchant_id' => 'Merchant ID',
    'coinpayments.public_key' => 'Public key',
    'coinpayments.private_key' => 'Private key',
    'coinpayments.ipn_secret' => 'IPN secret',
    'coinpayments.intro' => 'You can accept payments from many cryptocurrencies like Bitcoin, Monero, ZCash, etc.
        You will need to sign up for a Coin-Payments account, obtain your integration ID and keys and set it up below',
    'coinpayments.checkout.processing_payment.intro' => 'This process is automatic. Please do not close this browser/tab or change page.
        <br> Your browser will redirect to next page shortly.',
    'coinpayments.checkout.processing_payment' => 'Processing your payment... please wait!',
    'coinpayments.status_code' => 'Status code',
    'coinpayments.plan' => 'Plan',
    'coinpayments.next_period_day' => 'Next period end day',
    'coinpayments.amount' => 'Amount',
    'coinpayments.checkout_url' => 'Checkout URL',
    'coinpayments.status_url' => 'Status URL',
    'coinpayments.status' => 'Status',
    'coinpayments.pending.intro' => 'You have unpaid invoice.
        Click on the <strong>checkout url</strong> to pay or <strong>status url</strong> below to pay/check your payment status',
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
);
