<html lang="en">
    <head>
        <title>{{ trans('cashier::messages.paystack.change_plan') }}</title>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
        <script type="text/javascript" src="https://code.jquery.com/jquery-3.4.1.min.js"></script>            
        <link rel="stylesheet" href="{{ \Acelle\Cashier\Cashier::public_url('/vendor/acelle-cashier/css/main.css') }}">
    </head>
    
    <body>
        <div class="main-container row mt-40">
            <div class="col-md-2"></div>
            <div class="col-md-4 mt-40 pd-60">
                <label class="text-semibold text-muted mb-20 mt-0">
                    <strong>
                        {{ trans('cashier::messages.paystack.change_plan') }}
                    </strong>
                </label>
                <img class="rounded" width="100%" src="{{ \Acelle\Cashier\Cashier::public_url('/vendor/acelle-cashier/image/paystack.svg') }}" />
            </div>
            <div class="col-md-4 mt-40 pd-60">                
                <label>{{ trans('cashier::messages.paystack.change_plan') }}</label>  
                <h2 class="mb-40">{{ $newPlan->getBillableName() }}</h2>

                <p>{!! trans('cashier::messages.change_plan.intro', [
                    'plan' => $subscription->plan->getBillableName(),
                    'new_plan' => $newPlan->getBillableName(),
                ]) !!}</p>   
                
                <ul class="dotted-list topborder section mb-4">
                    <li>
                        <div class="unit size1of2">
                            {{ trans('cashier::messages.stripe.new_plan') }}
                        </div>
                        <div class="lastUnit size1of2">
                            <mc:flag>{{ $newPlan->getBillableName() }}</mc:flag>
                        </div>
                    </li>
                    <li>
                        <div class="unit size1of2">
                            {{ trans('cashier::messages.stripe.next_period_day') }}
                        </div>
                        <div class="lastUnit size1of2">
                            <mc:flag>{{ $nextPeriodDay->format('d M, Y') }}</mc:flag>
                        </div>
                    </li>
                    <li>
                        <div class="unit size1of2">
                            {{ trans('cashier::messages.stripe.amount') }}
                        </div>
                        <div class="lastUnit size1of2">
                            <mc:flag>{{ $amount }} ({{ $newPlan->getBillableCurrency() }})</mc:flag>
                        </div>
                    </li>
                </ul>
                
                @if($amount == 0)
                    <form id="checkoutForm" method="GET" action="{{ \Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\PaystackController@paymentRedirect') }}">
                        <input type="hidden" name="redirect" value="{{ \Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\PaystackController@changePlan', [
                            'subscription_id' => $subscription->uid,
                            'plan_id' => $newPlan->getBillableId(),
                        ]) }}" />

                        <button class="btn btn-primary mr-2">{{ trans('cashier::messages.paystack.change_plan_proceed') }}</button>
                        <a
                            href="{{ $return_url }}"
                            class="btn btn-secondary mr-10"
                        >{{ trans('cashier::messages.stripe.return_back') }}</a>
                    </form>
                    
                @elseif(!$service->getCard($subscription))
                        <a href="javascript:;" class="btn btn-secondary full-width mb-4" onclick="payWithPaystack();">
                            {{ trans('cashier::messages.paystack.update_payment_and_proceed') }}
                        </a>

                        <a
                            href="{{ $return_url }}"
                            class="text-muted" style="text-decoration: underline; display: block"
                        >{{ trans('cashier::messages.return_back') }}</a>
                @else
                    <div class="sub-section">
                        <h4 class="text-semibold mb-3 mt-4">{!! trans('cashier::messages.paystack.card_list') !!}</h4>
                        <ul class="dotted-list topborder section mb-4">
                            <li>
                                <div class="unit size1of2">
                                    {{ trans('messages.card.holder') }}
                                </div>
                                <div class="lastUnit size1of2">
                                    <mc:flag>{{ $service->getCard($subscription)['email'] }}</mc:flag>
                                </div>
                            </li>
                            <li>
                                <div class="unit size1of2">
                                    {{ trans('messages.card.last4') }}
                                </div>
                                <div class="lastUnit size1of2">
                                    <mc:flag>{{ $service->getCard($subscription)['last4'] }}</mc:flag>
                                </div>
                            </li>
                        </ul>
                        
                        <form id="checkoutForm" method="GET" action="{{ \Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\PaystackController@paymentRedirect') }}">
                            <input type="hidden" name="redirect" value="{{ \Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\PaystackController@changePlan', [
                                'subscription_id' => $subscription->uid,
                                'plan_id' => $newPlan->getBillableId(),
                                'use_old_card' => true,
                            ]) }}" />

                            <button class="btn btn-primary mr-2">{{ trans('cashier::messages.paystack.pay_with_this_card') }}</button>
                            <a href="javascript:;" class="btn btn-secondary" onclick="payWithPaystack();">{{ trans('cashier::messages.paystack.pay_with_new_card') }}</a>
                        </form>
                    </div>

                    <a
                        href="{{ $return_url }}"
                        class="text-muted" style="text-decoration: underline; display: block"
                    >{{ trans('cashier::messages.return_back') }}</a>
                @endif

                <form id="paymentForm">
                </form>
                <script src="https://js.paystack.co/v1/inline.js"></script> 

                <form id="checkoutForm" method="GET" action="{{ \Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\PaystackController@paymentRedirect') }}">
                    <input type="hidden" name="redirect" value="" />
                </form>
                
                <script>
                    var paymentForm = document.getElementById('paymentForm');
                    paymentForm.addEventListener('submit', payWithPaystack, false);
                    function payWithPaystack() {
                        var handler = PaystackPop.setup({
                            key: '{{ $service->public_key }}', // Replace with your public key
                            email: '{{ $subscription->user->getBillableEmail() }}',
                            amount: {{ $amount }} * 100, // the amount value is multiplied by 100 to convert to the lowest currency unit
                            currency: '{{ $newPlan->getBillableCurrency() }}', // Use GHS for Ghana Cedis or USD for US Dollars
                            firstname: '',
                            lastname: '',
                            reference: ''+Math.floor((Math.random() * 1000000000) + 1), // Replace with a reference you generated
                            callback: function(response) {
                                var reference = response.reference;
                                var url = '{{ \Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\PaystackController@changePlan', [
                                    'subscription_id' => $subscription->uid,
                                    'plan_id' => $newPlan->getBillableId(),
                                ]) }}&reference=' + reference;
                                
                                $('[name="redirect"]').val(url);
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
            <div class="col-md-2"></div>
        </div>
        <br />
        <br />
        <br />
    </body>
</html>