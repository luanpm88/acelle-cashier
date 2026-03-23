@extends('cashier::layouts.checkout')

@section('content')
    <label class="d-block text-semibold text-muted mb-20 mt-0">
        <strong>{{ trans('cashier::messages.braintree_subscription.checkout_title') }}</strong>
    </label>
    <img class="rounded" width="250" src="{{ \App\Cashier\Cashier::public_url('/vendor/acelle-cashier/image/braintree.png') }}" />

    <div class="py-4">
        @if ($errors->has('payment'))
            <div class="alert alert-danger mb-3">{{ $errors->first('payment') }}</div>
        @endif

        <p class="text-muted mb-3">
            {{ trans('cashier::messages.braintree_subscription.subscribing_to') }} <strong>{{ $mapping->remote_plan_name }}</strong>
            — {{ number_format($mapping->remote_price, 2) }} {{ $mapping->remote_currency }}/{{ $mapping->remote_interval_unit }}
        </p>

        <script src="https://js.braintreegateway.com/web/dropin/1.43.0/js/dropin.min.js"></script>
        <div id="dropin-container"></div>

        <button style="width: 100%" id="submit-button" class="btn btn-dark full-width mt-10 py-2 fs-6">
            {{ trans('cashier::messages.braintree.pay') }} {{ number_format($invoice->total(), 2) }} ({{ $invoice->getCurrencyCode() }})
        </button>

        <form id="paymentForm" style="display: none"
            action="{{ \App\Cashier\Cashier::lr_action('\App\Cashier\Controllers\BraintreeSubscriptionController@checkout', [
                'invoice_uid' => $invoice->uid,
                'payment_gateway_id' => $paymentGateway->uid,
            ]) }}" method="POST">
            {{ csrf_field() }}
            <input type="hidden" name="return_url" value="{{ request()->return_url }}" />
            <input type="hidden" name="payment_method_nonce" value="" />
        </form>

        <script>
            var button = document.querySelector('#submit-button');

            braintree.dropin.create({
                authorization: '{{ $clientToken }}',
                selector: '#dropin-container'
            }, function (err, instance) {
                if (err) {
                    console.error('Braintree Drop-In error:', err);
                    return;
                }
                button.addEventListener('click', function () {
                    button.disabled = true;
                    button.textContent = '{{ trans('cashier::messages.braintree_subscription.processing') }}';
                    instance.requestPaymentMethod(function (err, payload) {
                        if (err) {
                            button.disabled = false;
                            button.textContent = '{{ trans('cashier::messages.braintree.pay') }} {{ number_format($invoice->total(), 2) }} ({{ $invoice->getCurrencyCode() }})';
                            return;
                        }
                        document.querySelector('[name="payment_method_nonce"]').value = payload.nonce;
                        document.getElementById('paymentForm').submit();
                    });
                });
            });
        </script>
    </div>
@endsection
