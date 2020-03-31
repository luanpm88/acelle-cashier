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
                @foreach (['danger', 'warning', 'info', 'error'] as $msg)
                    @php
                        $class = $msg;
                        if ($msg == 'error') {
                            $class = 'danger';
                        }
                    @endphp
                    @if(Session::has('alert-' . $msg))
                        <!-- Form Error List -->
                        <div class="alert alert-{{ $class }} alert-noborder">
                            <button data-dismiss="alert" class="close" type="button"><span>×</span><span class="sr-only">Close</span></button>
                            <strong>{{ trans('messages.' . $msg) }}</strong>

                            <br>

                            <p>{!! preg_replace('/[\r\n]+/', ' ', Session::get('alert-' . $msg)) !!}</p>
                        </div>
                    @endif    
                @endforeach

                <label>{{ $subscription->plan->getBillableName() }}</label>               
                <h2 class="mb-40">{{ $subscription->plan->getBillableFormattedPrice() }}</h2>   

                <p>{!! trans('cashier::messages.paypal.checkout.intro', [
                    'plan' => $subscription->plan->getBillableName(),
                    'price' => $subscription->plan->getBillableFormattedPrice(),
                ]) !!}</p>

                <ul class="dotted-list topborder section mb-4">
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
                            {{ trans('cashier::messages.paypal.next_period_day') }}
                        </div>
                        <div class="lastUnit size1of2">
                            <mc:flag>{{ $subscription->current_period_ends_at }}</mc:flag>
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
                    <li>
                        <div class="unit size1of2">
                            {{ trans('cashier::messages.paypal.interval') }}
                        </div>
                        <div class="lastUnit size1of2">
                            <mc:flag>
                                {{ trans_choice('cashier::messages.interval.' . $subscription->plan->getBillableInterval(), $subscription->plan->getBillableIntervalCount()) }}
                            </mc:flag>
                        </div>
                    </li>
                </ul>

                <script
                    src="https://www.paypal.com/sdk/js?client-id={{ $service->client_id }}&vault=true">
                </script>

                <div id="paypal-button-container"></div>

                <script>
                    var form = jQuery('<form>', {
                        'action': '{{ action('\Acelle\Cashier\Controllers\PaypalSubscriptionController@paymentRedirect') }}',
                        'target': '_top',
                        'method': 'GET'
                    }).append(jQuery('<input>', {
                        'name': '_token',
                        'value': '{{ csrf_token() }}',
                        'type': 'hidden'
                    }));

                    $('body').append(form);

                    paypal.Buttons({
                        createSubscription: function(data, actions) {
                            return actions.subscription.create({
                                'plan_id': '{{ $paypalPlan['id'] }}'
                            });
                        },
                        onApprove: function(data, actions) {
                            form.append(jQuery('<input>', {
                                'name': 'subscriptionID',
                                'value': data.subscriptionID,
                                'type': 'hidden'
                            }));
                            form.append(jQuery('<input>', {
                                'name': 'redirect',
                                'value': '{{ action('\Acelle\Cashier\Controllers\PaypalSubscriptionController@checkout', $subscription->uid) }}',
                                'type': 'hidden'
                            }));
                            form.submit();
                        }
                    }).render('#paypal-button-container');
                </script>

                <form class="mt-5" method="POST" action="{{ action('\Acelle\Cashier\Controllers\PaypalSubscriptionController@cancelNow', ['subscription_id' => $subscription->uid]) }}">
                    {{ csrf_field() }}
                    
                    <a href="javascript:;" onclick="$(this).closest('form').submit()"
                        class="text-muted" style="font-size: 12px; text-decoration: underline"
                    >{{ trans('cashier::messages.paypal_subscription.cancel_new_subscription') }}</a>
                </form>
            </div>
            <div class="col-md-2"></div>
        </div>
        <br />
        <br />
        <br />
    </body>
</html>