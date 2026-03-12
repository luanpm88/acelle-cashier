@extends('cashier::layouts.checkout')

@section('content')
    <span class="me-2">
        <svg width="50" xmlns="http://www.w3.org/2000/svg" viewBox="54 36 360.02 149.84">
            <style>.st0{fill-rule:evenodd;clip-rule:evenodd;fill:#635BFF;}</style>
            <g>
                <path class="st0" d="M414,113.4c0-25.6-12.4-45.8-36.1-45.8c-23.8,0-38.2,20.2-38.2,45.6c0,30.1,17,45.3,41.4,45.3c11.9,0,20.9-2.7,27.7-6.5v-20c-6.8,3.4-14.6,5.5-24.5,5.5c-9.7,0-18.3-3.4-19.4-15.2h48.9C413.8,121,414,115.8,414,113.4z M364.6,103.9c0-11.3,6.9-16,13.2-16c6.1,0,12.6,4.7,12.6,16H364.6z"/>
                <path class="st0" d="M301.1,67.6c-9.8,0-16.1,4.6-19.6,7.8l-1.3-6.2h-22v116.6l25-5.3l0.1-28.3c3.6,2.6,8.9,6.3,17.7,6.3c17.9,0,34.2-14.4,34.2-46.1C335.1,83.4,318.6,67.6,301.1,67.6z M295.1,136.5c-5.9,0-9.4-2.1-11.8-4.7l-0.1-37.1c2.6-2.9,6.2-4.9,11.9-4.9c9.1,0,15.4,10.2,15.4,23.3C310.5,126.5,304.3,136.5,295.1,136.5z"/>
                <polygon class="st0" points="223.8,61.7 248.9,56.3 248.9,36 223.8,41.3"/>
                <rect x="223.8" y="69.3" class="st0" width="25.1" height="87.5"/>
                <path class="st0" d="M196.9,76.7l-1.6-7.4h-21.6v87.5h25V97.5c5.9-7.7,15.9-6.3,19-5.2v-23C214.5,68.1,202.8,65.9,196.9,76.7z"/>
                <path class="st0" d="M146.9,47.6l-24.4,5.2l-0.1,80.1c0,14.8,11.1,25.7,25.9,25.7c8.2,0,14.2-1.5,17.5-3.3V135c-3.2,1.3-19,5.9-19-8.9V90.6h19V69.3h-19L146.9,47.6z"/>
                <path class="st0" d="M79.3,94.7c0-3.9,3.2-5.4,8.5-5.4c7.6,0,17.2,2.3,24.8,6.4V72.2c-8.3-3.3-16.5-4.6-24.8-4.6C67.5,67.6,54,78.2,54,95.9c0,27.6,38,23.2,38,35.1c0,4.6-4,6.1-9.6,6.1c-8.3,0-18.9-3.4-27.3-8v23.8c9.3,4,18.7,5.7,27.3,5.7c20.8,0,35.1-10.3,35.1-28.2C117.4,100.6,79.3,105.9,79.3,94.7z"/>
            </g>
        </svg>
    </span>
    <div class="py-5">
        @if ($errors->has('payment'))
            <div class="alert alert-danger mb-3">{{ $errors->first('payment') }}</div>
        @endif

        <p class="text-muted mb-3">
            Subscribing to <strong>{{ $mapping->remote_plan_name }}</strong>
            — {{ number_format($mapping->remote_price, 2) }} {{ $mapping->remote_currency }}/{{ $mapping->remote_interval_unit }}
        </p>

        <h2 class="mb-4">{{ trans('cashier::messages.stripe.new_card') }}</h2>
        <p class="text-muted">{{ trans('cashier::messages.stripe.new_card.intro2') }}</p>
        <div id="card-element" class="form-control py-3 shadow-sm" style="height: auto!important;"></div>
        <div id="card-errors" class="text-danger mt-2" role="alert"></div>
        <button id="submit" class="btn btn-dark w-100 mt-4 py-2 fs-5">
            {{ trans('cashier::messages.stripe.pay') }} {{ number_format($invoice->total(), 2) }} ({{ $invoice->getCurrencyCode() }})
        </button>
    </div>

    <script>
        var stripe = Stripe('{{ $publishableKey }}');
        var elements = stripe.elements();
        var card = elements.create("card", {
            style: { base: { color: "#32325d" } },
            hidePostalCode: true
        });
        card.mount("#card-element");

        card.on('change', function(event) {
            var el = document.getElementById('card-errors');
            el.textContent = event.error ? event.error.message : '';
        });

        var checkoutUrl = '{{ \Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\StripeSubscriptionController@checkout', ['invoice_uid' => $invoice->uid, 'payment_gateway_id' => $paymentGateway->uid]) }}';
        var confirmUrl = '{{ \Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\StripeSubscriptionController@confirm', ['invoice_uid' => $invoice->uid]) }}';
        var returnUrl = '{{ Billing::getReturnUrl() }}';
        var csrfToken = '{{ csrf_token() }}';
        var gatewayUid = '{{ $paymentGateway->uid }}';
        var payLabel = '{{ trans('cashier::messages.stripe.pay') }} {{ number_format($invoice->total(), 2) }} ({{ $invoice->getCurrencyCode() }})';

        function showError(msg) {
            document.getElementById('card-errors').textContent = msg;
            var btn = document.getElementById('submit');
            btn.disabled = false;
            btn.textContent = payLabel;
        }

        document.getElementById('submit').addEventListener('click', function() {
            var btn = this;
            btn.disabled = true;
            btn.textContent = 'Processing...';
            document.getElementById('card-errors').textContent = '';

            // Step 1: Create PaymentMethod from card (no SetupIntent needed)
            stripe.createPaymentMethod({
                type: 'card',
                card: card,
                billing_details: {
                    name: '{{ $invoice->getBillingName() }}',
                    email: '{{ $invoice->billing_email }}'
                }
            }).then(function(result) {
                if (result.error) {
                    showError(result.error.message);
                    return;
                }

                // Step 2: Send PaymentMethod to server to create subscription
                btn.textContent = 'Creating subscription...';
                $.ajax({
                    url: checkoutUrl,
                    type: 'POST',
                    data: { _token: csrfToken, payment_method_id: result.paymentMethod.id },
                    globalError: false
                }).done(function(response) {
                    if (response && response.requires_action && response.client_secret) {
                        // Step 3a: Subscription needs payment confirmation (3DS / SCA)
                        btn.textContent = 'Confirming payment...';
                        stripe.confirmCardPayment(response.client_secret).then(function(piResult) {
                            if (piResult.error) {
                                showError(piResult.error.message);
                                return;
                            }
                            // Step 4: Payment confirmed — tell server to activate
                            btn.textContent = 'Activating subscription...';
                            $.ajax({
                                url: confirmUrl,
                                type: 'POST',
                                data: { _token: csrfToken, payment_gateway_id: gatewayUid },
                                globalError: false
                            }).done(function(resp) {
                                window.location = (resp && resp.redirect_url) || returnUrl || '/';
                            }).fail(function(jqXHR) {
                                var msg = 'Activation failed';
                                try { msg = JSON.parse(jqXHR.responseText).error || msg; } catch(e) {}
                                showError(msg);
                            });
                        });
                    } else if (response && response.redirect_url) {
                        // Step 3b: Payment succeeded immediately — redirect
                        window.location = response.redirect_url;
                    } else {
                        window.location = returnUrl || '/';
                    }
                }).fail(function(jqXHR) {
                    var msg = 'Payment failed';
                    try { msg = JSON.parse(jqXHR.responseText).error || msg; } catch(e) {}
                    showError(msg);
                });
            });
        });
    </script>
@endsection
