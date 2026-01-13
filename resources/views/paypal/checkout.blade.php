@extends('cashier::layouts.checkout')

@section('content')
    <label class="d-block text-semibold text-muted mb-20 mt-0">
        <strong>
            {{ trans('cashier::messages.paypal') }}
        </strong>
    </label>
    <img class="rounded" width="100" src="{{ \Acelle\Cashier\Cashier::public_url('/vendor/acelle-cashier/image/paypal-logo.png') }}" />

    <div class="py-4">
        <h2 class="mb-40">{{ $invoice->title }}</h2>   

        <p>{!! trans('cashier::messages.paypal.checkout.intro', [
            'price' => $invoice->formattedTotal(),
        ]) !!}</p>

        <script
            src="https://www.paypal.com/sdk/js?client-id={{ $service->clientId }}&currency={{ $invoice->getCurrencyCode() }}"> // Required. Replace SB_clientId with your sandbox client ID.
        </script>
            
        <div id="paypal-button-container"></div>

        <script>
            $(function() {
                jQuery.ajax({
                    url:      'https://www.paypal.com/sdk/js?client-id={{ $service->clientId }}&currency={{ $invoice->getCurrencyCode() }}',
                    dataType: 'text',
                    type:     'GET',
                    complete:  function(xhr){
                        if(typeof cb === 'function')
                        cb.apply(this, [xhr.status]);
                    }
                }).fail(function(e) {alert(e.responseText)});
            
                var form = jQuery('<form>', {
                    'action': '{{ \Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\PaypalController@checkout', [
                        'invoice_uid' => $invoice->uid,
                        'payment_gateway_id' => $paymentGateway->uid,
                    ]) }}',
                    'target': '_top',
                    'method': 'POST'
                }).append(jQuery('<input>', {
                    'name': '_token',
                    'value': '{{ csrf_token() }}',
                    'type': 'hidden'
                }));

                $('body').append(form);

                paypal.Buttons({
                    createOrder: function(data, actions) {
                        // This function sets up the details of the transaction, including the amount and line item details.
                        return actions.order.create({
                            purchase_units: [{
                                amount: {
                                    value: Math.round(parseFloat('{{ $invoice->total() }}') * 100) / 100, // keep 2 number in decimal
                                }
                            }]
                        });
                    },
                    onApprove: function(data, actions) {
                        // This function captures the funds from the transaction.
                        return actions.order.capture().then(function(details) {
                            form.append(jQuery('<input>', {
                                'name': 'orderID',
                                'value': data.orderID,
                                'type': 'hidden'
                            }));
                            form.submit();
                        });
                    },
                    onError: function (err) {
                        // For example, redirect to a specific error page
                        alert(err);
                    }
                }).render('#paypal-button-container');
            });
        </script>
    </div>
@endsection