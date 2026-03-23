@extends('cashier::layouts.checkout')

@section('content')
    <label class="d-block text-semibold text-muted mb-20 mt-0">
        <strong>
            {{ trans('cashier::messages.paystack') }}
        </strong>
    </label>
    <img class="rounded" width="250" src="{{ \App\Cashier\Cashier::public_url('/vendor/acelle-cashier/image/paystack.svg') }}" />

    <div class="py-4">
        <h2 class="mb-3">{!! $invoice->title !!}</h2>              
        <label>{!! $invoice->description !!}</label>  
        <hr>
        
        
        <p>{!! trans('cashier::messages.paystack.click_bellow_to_pay', [
            'price' => $invoice->formattedTotal(),
        ]) !!}</p>

        <form id="paymentForm">
            <a href="javascript:;" class="btn btn-dark w-100 py-2 fs-6" onclick="payWithPaystack()">
                {{ trans('cashier::messages.paystack.pay') }}
            </a>
        </form>
        <script src="https://js.paystack.co/v1/inline.js"></script> 

        <form id="checkoutForm" method="POST" action="{{ \App\Cashier\Cashier::lr_action('\App\Cashier\Controllers\PaystackController@checkout', [
            'invoice_uid' => $invoice->uid,
            'payment_gateway_id' => $paymentGateway->uid,
        ]) }}">
            {{ csrf_field() }}
            <input type="hidden" name="reference" value="" />
        </form>
        
        <script>
            var paymentForm = document.getElementById('paymentForm');
            paymentForm.addEventListener('submit', payWithPaystack, false);
            function payWithPaystack() {
                var handler = PaystackPop.setup({
                    key: '{{ $service->publicKey }}', // Replace with your public key
                    email: '{{ $invoice->billing_email }}',
                    amount: {{ $invoice->total() }} * 100, // the amount value is multiplied by 100 to convert to the lowest currency unit
                    currency: '{{ $invoice->getCurrencyCode() }}', // Use GHS for Ghana Cedis or USD for US Dollars
                    firstname: '{{ $invoice->billing_first_name }}',
                    lastname: '{{ $invoice->billing_last_name }}',
                    phone: '{{ $invoice->billing_phone }}',
                    reference: ''+Math.floor((Math.random() * 1000000000) + 1), // Replace with a reference you generated
                    callback: function(response) {
                        var reference = response.reference;
                        
                        $('[name="reference"]').val(reference);
                        $('#checkoutForm').submit();
                    },
                    onClose: function() {
                        alert('Transaction was not completed, window closed.');
                    },
                });
                handler.openIframe();
            }
        </script>
    </div>
@endsection