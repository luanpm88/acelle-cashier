<html lang="en">
    <head>
        <title>{{ trans('cashier::messages.stripe.change_plan') }}</title>
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
                        {{ trans('cashier::messages.stripe.change_plan') }}
                    </strong>
                </label>
                <img class="rounded" width="100%" src="{{ url('/vendor/acelle-cashier/image/stripe.png') }}" />
            </div>
            <div class="col-md-4 mt-40 pd-60">                
                <label>{{ trans('cashier::messages.stripe.new_plan') }}</label>  
                <h2 class="mb-40">{{ $newPlan->getBillableName() }}</h2>

                <p>{!! trans('cashier::messages.stripe.change_plan.intro', [
                    'plan' => $subscription->plan->getBillableName(),
                    'new_plan' => $newPlan->getBillableName(),
                ]) !!}</p>   
                
                <ul class="dotted-list topborder section mb-4">
                    <li>
                        <div class="unit size1of2">
                            {{ trans('cashier::messages.direct.new_plan') }}
                        </div>
                        <div class="lastUnit size1of2">
                            <mc:flag>{{ $newPlan->getBillableName() }}</mc:flag>
                        </div>
                    </li>
                    <li>
                        <div class="unit size1of2">
                            {{ trans('cashier::messages.direct.next_period_day') }}
                        </div>
                        <div class="lastUnit size1of2">
                            <mc:flag>{{ $nextPeriodDay->format('d M, Y') }}</mc:flag>
                        </div>
                    </li>
                    <li>
                        <div class="unit size1of2">
                            {{ trans('cashier::messages.direct.amount') }}
                        </div>
                        <div class="lastUnit size1of2">
                            <mc:flag>{{ round($amount) }} ({{ $newPlan->getBillableCurrency() }})</mc:flag>
                        </div>
                    </li>
                </ul>
                
                @if($service->getCardInformation($subscription->user) == NULL)
                    <div class="alert alert-danger">
                        {{ trans('cashier::messages.stripe.payment_outdated.alert') }}
                    </div>

                    <a href="javascript:;" class="btn btn-secondary full-width" onclick="$('#stripe_button button').click()">
                        {{ trans('cashier::messages.stripe.update_payment_and_proceed') }}
                    </a>

                    <form id="stripe_button" style="display: none" action="{{ action('\Acelle\Cashier\Controllers\StripeController@updateCard', [
                        '_token' => csrf_token(),
                        'subscription_id' => $subscription->uid,
                    ]) }}" method="POST">
                        <input type="hidden" name="redirect" value="{{ action('\Acelle\Cashier\Controllers\StripeController@changePlanPending', [
                            'subscription_id' => $subscription->uid,
                            'plan_id' => $newPlan->getBillableId(),
                        ]) }}" />

                        <script
                        src="https://checkout.stripe.com/checkout.js" class="stripe-button"
                        data-key="{{ $service->publishableKey }}"
                        data-amount="{{ $service->convertPrice($amount, $newPlan->getBillableCurrency()) }}"
                        data-currency="{{ $newPlan->getBillableCurrency() }}"
                        data-name="{{ $newPlan->getBillableName() }}"
                        data-email="{{ $subscription->user->getBillableEmail() }}"
                        data-description="{{ $newPlan->description }}"
                        data-image="https://stripe.com/img/documentation/checkout/marketplace.png"
                        data-locale="{{ language_code() }}"
                        data-zip-code="true"
                        data-label="{{ trans('messages.pay_with_strip_label_button') }}">
                        </script>
                    </form>
                @else
                    <a
                        href="{{ action('\Acelle\Cashier\Controllers\StripeController@changePlanPending', [
                            'subscription_id' => $subscription->uid,
                            'plan_id' => $newPlan->getBillableId(),
                        ]) }}"
                        class="btn btn-primary mr-10 mr-2"
                    >{{ trans('cashier::messages.stripe.change_plan.proceed') }}</a>
                        
                    <a
                        href="{{ $return_url }}"
                        class="btn btn-secondary mr-10"
                    >{{ trans('cashier::messages.direct.return_back') }}</a>
                @endif
                
            </div>
            <div class="col-md-2"></div>
        </div>
        <br />
        <br />
        <br />
    </body>
</html>