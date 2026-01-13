@extends('cashier::layouts.checkout')

@section('content')
    <label class="d-block text-semibold text-muted mb-20 mt-0">
        <strong>
            {{ trans('cashier::messages.braintree.checkout_with_braintree') }}
        </strong>
    </label>
    <img class="rounded" width="250" src="{{ \Acelle\Cashier\Cashier::public_url('/vendor/acelle-cashier/image/braintree.png') }}" />

    <div class="py-4">
        <script src="https://js.braintreegateway.com/web/dropin/1.6.1/js/dropin.js"></script>
        <div id="dropin-container"></div>
        
        <a style="width: 100%" id="submit-button" href="javascript:;" class="btn btn-dark full-width mt-10 py-2 fs-6">
            {{ trans('cashier::messages.braintree.pay') }}
        </a>
            
        <form id="updateCard" style="display: none"
            action="{{ \Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\BraintreeController@checkout', [
                'invoice_uid' => $invoice->uid,
                'payment_gateway_id' => $paymentGateway->uid,
            ]) }}" method="POST">
                {{ csrf_field() }}
                <input type="hidden" name="return_url" value="{{ request()->return_url }}" />
                <input type="hidden" name="nonce" value="" />
        </form>
        
        <script>
            var button = document.querySelector('#submit-button');

            braintree.dropin.create({
                authorization: '{{ $clientToken }}',
                selector: '#dropin-container'
            }, function (err, instance) {
                button.addEventListener('click', function () {
                instance.requestPaymentMethod(function (err, payload) {
                    // Submit payload.nonce to your server
                    $('[name="nonce"]').val(payload.nonce);
                    $('#updateCard').submit();
                });
                })
            });
        </script>
    </div>
@endsection