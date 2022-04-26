<html lang="en">
    <head>
        <title>{{ trans('cashier::messages.paypal') }}</title>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
        <script type="text/javascript" src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
        
        <link rel="stylesheet" href="{{ \Acelle\Cashier\Cashier::public_url('/vendor/acelle-cashier/css/main.css') }}">
    </head>
    
    <body>
        <div class="row mt-40">
            <div class="col-md-2"></div>
            <div class="col-md-4 mt-40 pd-60">
                <label class="text-semibold text-muted mb-20 mt-0">
                    <strong>
                        {{ trans('cashier::messages.paypal') }}
                    </strong>
                </label>
                <div class="text-center">
                    <img width="60%" src="{{ \Acelle\Cashier\Cashier::public_url('/vendor/acelle-cashier/image/paypal-logo.png') }}" />
                </div>
            </div>
            <div class="col-md-4 mt-40 pd-60">            
                <h2 class="mb-40">{{ $invoice->title }}</h2>   

                <p>{!! trans('cashier::messages.paypal.checkout.intro', [
                    'price' => $invoice->formattedTotal(),
                ]) !!}</p>

                <script
                    src="https://www.paypal.com/sdk/js?client-id={{ $service->clientId }}&currency={{ $invoice->currency->code }}"> // Required. Replace SB_clientId with your sandbox client ID.
                </script>
                    
                <div id="paypal-button-container"></div>

                <script>
                    var form = jQuery('<form>', {
                        'action': '{{ \Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\PaypalController@checkout', [
                            'invoice_uid' => $invoice->uid,
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
                </script>

                <div class="my-4">
                    <hr>
                    <form id="cancelForm" method="POST" action="{{ action('SubscriptionController@cancelInvoice', [
                                'invoice_uid' => $invoice->uid,
                    ]) }}">
                        {{ csrf_field() }}
                        <a href="javascript:;" onclick="$('#cancelForm').submit()">
                            {{ trans('messages.subscription.cancel_now_change_other_plan') }}
                        </a>
                    </form>
                    
                </div>
            </div>
        </div>
        <br />
        <br />
        <br />
    </body>
</html>