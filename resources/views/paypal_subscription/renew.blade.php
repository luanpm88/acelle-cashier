<html lang="en">
    <head>
        <title>{{ trans('cashier::messages.paypal.renew_subscription') }}</title>
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
                    
                <p>{!! trans('cashier::messages.paypal.renew_plan.intro', [
                    'plan' => $subscription->plan->getBillableName(),
                ]) !!}</p>
                    
                <ul class="dotted-list topborder section mb-4">
                    <li>
                        <div class="unit size1of2">
                            {{ trans('cashier::messages.paypal.next_period_day') }}
                        </div>
                        <div class="lastUnit size1of2">
                            <mc:flag>{!! $subscription->nextPeriod() !!}</mc:flag>
                        </div>
                    </li>
                    <li>
                        <div class="unit size1of2">
                            {{ trans('cashier::messages.paypal.plan') }}
                        </div>
                        <div class="lastUnit size1of2">
                            <mc:flag>{{ $subscription->plan->getBillableName() }}</mc:flag>
                        </div>
                    </li>
                    <li>
                        <div class="unit size1of2">
                            {{ trans('cashier::messages.paypal.amount') }}
                        </div>
                        <div class="lastUnit size1of2">
                            <mc:flag>{{ $subscription->plan->getBillableFormattedPrice() }}</mc:flag>
                        </div>
                    </li>
                </ul>
                
                @if ($subscription->plan->price == 0)
                    <form method="POST" action="{{ action('\Acelle\Cashier\Controllers\PaypalController@renew', ['subscription_id' => $subscription->uid]) }}">
                        {{ csrf_field() }}
                        <input type='hidden' name='plan_id' value='{{ $subscription->plan->getBillableId() }}' />
                        
                        <button
                            class="btn btn-primary mr-10 mr-2"
                        >{{ trans('cashier::messages.paypal.renew_proceed') }}</button>
                            
                        <a
                        href="{{ $return_url }}"
                            class="btn btn-secondary mr-10"
                        >{{ trans('cashier::messages.paypal.return_back') }}</a>
                    </form>
                @else
                    <p>{!! trans('cashier::messages.paypal.click_to_proceed', [
                        'plan' => $subscription->plan->getBillableName(),
                        'new_plan' => $subscription->plan->getBillableName(),
                    ]) !!}</p>   

                    <script
                        src="https://www.paypal.com/sdk/js?client-id={{ $service->client_id }}&currency={{ $subscription->plan->getBillableCurrency() }}"> // Required. Replace SB_CLIENT_ID with your sandbox client ID.
                    </script>
                        
                    <div id="paypal-button-container"></div>

                    <script>
                        var form = jQuery('<form>', {
                            'action': '{{ action('\Acelle\Cashier\Controllers\PaypalController@paymentRedirect') }}',
                            'target': '_top',
                            'method': 'GET'
                        }).append(jQuery('<input>', {
                            'name': '_token',
                            'value': '{{ csrf_token() }}',
                            'type': 'hidden'
                        })).append(jQuery('<input>', {
                            'name': 'plan_id',
                            'value': '{{ request()->plan_id }}',
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
                                    form.append(jQuery('<input>', {
                                        'name': 'orderID',
                                        'value': data.orderID,
                                        'type': 'hidden'
                                    }));
                                    form.append(jQuery('<input>', {
                                        'name': 'redirect',
                                        'value': '{{ action('\Acelle\Cashier\Controllers\PaypalController@renew', $subscription->uid) }}',
                                        'type': 'hidden'
                                    }));
                                    form.submit();
                                });
                            }
                        }).render('#paypal-button-container');
                    </script>
                    
                    <hr>
                    <a
                        href="{{ $return_url }}"
                            class="btn btn-secondary mr-10 mt-4"
                        >{{ trans('cashier::messages.paypal.return_back') }}</a>
                @endif
                    
            </div>
            <div class="col-md-2"></div>
        </div>
        <br />
        <br />
        <br />
    </body>
</html>