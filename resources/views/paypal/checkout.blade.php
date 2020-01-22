<html lang="en">
    <head>
        <title>{{ trans('cashier::messages.direct.checkout.page_title') }}</title>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
        <script type="text/javascript" src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
        
        <link rel="stylesheet" href="{{ url('/vendor/acelle-cashier/css/main.css') }}">
    </head>
    
    <body>
        <div class="row mt-40">
            <div class="col-md-2"></div>
            <div class="col-md-4 mt-40 pd-60">
                <label class="text-semibold text-muted mb-20 mt-0">
                    <strong>
                        {{ trans('cashier::messages.paypal.title') }}
                    </strong>
                </label>
                <div class="text-center">
                    <img width="60%" src="{{ url('/vendor/acelle-cashier/image/paypal-logo.png') }}" />
                </div>
            </div>
            <div class="col-md-4 mt-40 pd-60">
                <label>{{ $subscription->plan->getBillableName() }}</label>               
                <h2 class="mb-40">{{ $subscription->plan->getBillableFormattedPrice() }}</h2>   

                <p>{!! trans('cashier::messages.paypal.checkout.intro', [
                    'plan' => $subscription->plan->getBillableName(),
                    'price' => $subscription->plan->getBillableFormattedPrice(),
                ]) !!}</p>

                <script
                    src="https://www.paypal.com/sdk/js?client-id={{ $gatewayService->client_id }}"> // Required. Replace SB_CLIENT_ID with your sandbox client ID.
                </script>
                    
                <div id="paypal-button-container"></div>

                <script>
                    var form = jQuery('<form>', {
                        'action': '{{ action('\Acelle\Cashier\Controllers\PaypalController@checkout', $subscription->uid) }}',
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
                                        value: '{{ $subscription->plan->getBillableAmount() }}',
                                    }
                                }]
                            });
                        },
                        onApprove: function(data, actions) {
                            // This function captures the funds from the transaction.
                            return actions.order.capture().then(function(details) {
                                {{-- alert('Transaction completed by ' + details.payer.name.given_name);
                                    // Call your server to save the transaction
                                return fetch('{{ action('\Acelle\Cashier\Controllers\PaypalController@checkout', $subscription->uid) }}', {
                                    method: 'post',
                                    headers: {
                                        'content-type': 'application/json'
                                    },
                                    body: JSON.stringify({
                                        orderID: data.orderID,
                                        _token: '{{ csrf_token() }}',
                                    })
                                }); --}}

                                form.append(jQuery('<input>', {
                                    'name': 'orderID',
                                    'value': data.orderID,
                                    'type': 'hidden'
                                }));
                                form.submit();
                            });
                        }
                    }).render('#paypal-button-container');
                </script>
            </div>
            <div class="col-md-2"></div>
        </div>
        <br />
        <br />
        <br />
    </body>
</html>