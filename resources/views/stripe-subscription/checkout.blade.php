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
        <p class="text-muted mb-3">
            {{ trans('cashier::messages.stripe_subscription.subscribing_to') }} <strong>{{ $remotePlan->name }}</strong>
            — {{ number_format($remotePlan->price, 2) }} {{ $remotePlan->currency }}/{{ $remotePlan->intervalUnit }}
        </p>

        <h2 class="mb-4">{{ trans('cashier::messages.stripe.new_card') }}</h2>
        <p class="text-muted">{{ trans('cashier::messages.stripe.new_card.intro2') }}</p>
        <div id="card-element" class="form-control py-3 shadow-sm" style="height: auto!important;"></div>
        <div id="card-errors" class="text-danger mt-2" role="alert"></div>
        <button id="submit" class="btn btn-dark w-100 mt-4 py-2 fs-5">
            {{ trans('cashier::messages.stripe.pay') }} {{ number_format($intent->amount, 2) }} ({{ $intent->currency }})
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

        var confirmedPaymentMethodId = null;
        var payUrl = '{{ action("\App\Cashier\Controllers\StripeSubscriptionController@pay", ["intent_uid" => $intent->uid]) }}';
        var btnOriginalText = '{{ trans("cashier::messages.stripe.pay") }} {{ number_format($intent->amount, 2) }} ({{ $intent->currency }})';

        function resetButton() {
            var btn = document.getElementById('submit');
            btn.disabled = false;
            btn.textContent = btnOriginalText;
        }

        function submitPayment(paymentMethodId) {
            var btn = document.getElementById('submit');
            btn.textContent = '{{ trans("cashier::messages.stripe_subscription.completing") }}';
            $.ajax({
                url: payUrl,
                type: 'POST',
                data: {
                    _token: '{{ csrf_token() }}',
                    stripe_payment_method: paymentMethodId,
                    return_url: '{{ $returnUrl }}',
                }
            }).done(function(data) {
                if (data.requires_action && data.client_secret) {
                    handle3dsChallenge(data.client_secret);
                    return;
                }
                window.location = data.redirect_url || '{{ $returnUrl }}';
            }).fail(function(jqXHR) {
                var msg = jqXHR.responseJSON ? (jqXHR.responseJSON.error || jqXHR.responseJSON.message) : jqXHR.statusText;
                document.getElementById('card-errors').textContent = msg;
                resetButton();
            });
        }

        function handle3dsChallenge(clientSecret) {
            stripe.confirmCardPayment(clientSecret).then(function(result) {
                if (result.error) {
                    document.getElementById('card-errors').textContent = result.error.message;
                    resetButton();
                    return;
                }
                // Server reads sub_xxx from intent.remote_reference_id (server-stored, never trust client)
                $.ajax({
                    url: payUrl,
                    type: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        confirm_3ds: true,
                        return_url: '{{ $returnUrl }}',
                    }
                }).done(function(confirmData) {
                    window.location = confirmData.redirect_url || '{{ $returnUrl }}';
                }).fail(function(jqXHR) {
                    var msg = jqXHR.responseJSON ? (jqXHR.responseJSON.error || jqXHR.responseJSON.message) : jqXHR.statusText;
                    document.getElementById('card-errors').textContent = msg;
                    resetButton();
                });
            });
        }

        document.getElementById('submit').addEventListener('click', function() {
            var btn = this;
            btn.disabled = true;
            btn.textContent = '{{ trans("cashier::messages.stripe_subscription.processing") }}';

            if (confirmedPaymentMethodId) {
                submitPayment(confirmedPaymentMethodId);
                return;
            }

            stripe.confirmCardSetup('{{ $clientSecret }}', {
                payment_method: {
                    card: card,
                    billing_details: {
                        name: '{{ $intent->payer->billingName }}',
                        email: '{{ $intent->payer->email }}'
                    }
                }
            }).then(function(result) {
                if (result.error) {
                    document.getElementById('card-errors').textContent = result.error.message;
                    resetButton();
                    return;
                }
                if (result.setupIntent.status === 'succeeded') {
                    var pmId = result.setupIntent.payment_method;
                    if (typeof pmId === 'object' && pmId !== null) {
                        pmId = pmId.id;
                    }
                    if (!pmId) {
                        document.getElementById('card-errors').textContent = 'Could not retrieve payment method. Please try again.';
                        resetButton();
                        return;
                    }
                    confirmedPaymentMethodId = pmId;
                    submitPayment(pmId);
                }
            });
        });
    </script>
@endsection
