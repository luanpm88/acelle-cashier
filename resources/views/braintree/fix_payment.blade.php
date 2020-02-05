<html lang="en">
    <head>
        <title>{{ trans('cashier::messages.braintree.transaction.payment') }}</title>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
        <script type="text/javascript" src="https://code.jquery.com/jquery-3.4.1.min.js"></script>            
        <link rel="stylesheet" href="{{ url('/vendor/acelle-cashier/css/main.css') }}">
    </head>
    
    <body>
        <div class="main-container row mt-40">
            <div class="col-md-2"></div>
            <div class="col-md-4 mt-40 pd-60">
                <label class="text-semibold text-muted mb-20 mt-0">
                    <strong>
                        {{ trans('cashier::messages.braintree.payment') }}
                    </strong>
                </label>
                <img class="rounded" width="100%" src="{{ url('/vendor/acelle-cashier/image/braintree.png') }}" />
            </div>
            <div class="col-md-4 mt-40 pd-60">                
                <label>{{ trans('cashier::messages.braintree.transaction.payment') }}</label>  
                <h2 class="mb-40">{{ $subscription->plan->getBillableName() }}</h2>

                <p>{!! trans('cashier::messages.braintree.fix_payment.intro', [
                    'plan' => $subscription->plan->getBillableName(),
                    'new_plan' => $subscription->plan->getBillableFormattedPrice(),
                ]) !!}</p>   
                
                <ul class="dotted-list topborder section mb-4">
                    <li>
                        <div class="unit size1of2">
                            {{ trans('cashier::messages.transaction.title') }}
                        </div>
                        <div class="lastUnit size1of2">
                            <mc:flag>{{ $subscription->plan->getBillableName() }}</mc:flag>
                        </div>
                    </li>
                    <li>
                        <div class="unit size1of2">
                            {{ trans('cashier::messages.stripe.next_period_day') }}
                        </div>
                        <div class="lastUnit size1of2">
                            <mc:flag>{{ $subscription->nextPeriod()->format('d M, Y') }}</mc:flag>
                        </div>
                    </li>
                    <li>
                        <div class="unit size1of2">
                            {{ trans('cashier::messages.stripe.amount') }}
                        </div>
                        <div class="lastUnit size1of2">
                            <mc:flag>{{ $subscription->plan->getBillableFormattedPrice() }}</mc:flag>
                        </div>
                    </li>
                </ul>
                
                <h4 class="text-semibold mt-4">{!! trans('cashier::messages.braintree.pay_with_new_card') !!}</h4>
                    
                <script src="https://js.braintreegateway.com/web/dropin/1.6.1/js/dropin.js"></script>
                <div id="dropin-container"></div>
                
                <a id="submit-button" href="javascript:;" style="width: 100%" class="btn btn-secondary full-width mt-10">{{ trans('cashier::messages.braintree.pay') }}</a>
                    
                <form id="updateCard" style="display: none" action="{{ action('\Acelle\Cashier\Controllers\BraintreeController@updateCard', [
                    'subscription_id' => $subscription->uid,
                ]) }}" method="POST">
                    {{ csrf_field() }}
                    <input type="hidden" name="nonce" value="" />
                    <input type="hidden" name="redirect" value="{{ action('\Acelle\Cashier\Controllers\BraintreeController@paymentRedirect', [
                        'redirect' => action('\Acelle\Cashier\Controllers\BraintreeController@fixPayment', [
                            'subscription_id' => $subscription->uid,
                        ]),
                    ]) }}" />
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
                            $('#dropin-container').hide();
                        });
                        })
                    });
                </script>

                <a
                    href="{{ action('AccountSubscriptionController@index') }}"
                    class="text-muted mt-4" style="text-decoration: underline; display: block"
                >{{ trans('cashier::messages.braintree.return_back') }}</a>
                
            </div>
            <div class="col-md-2"></div>
        </div>
        <br />
        <br />
        <br />
    </body>
</html>