<html lang="en">
    <head>
        <title>{{ trans('cashier::messages.razorpay.change_plan') }}</title>
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
                        {{ trans('cashier::messages.razorpay.pay_with_razorpay') }}
                    </strong>
                </label>
                <div class="text-center">
                    <img width="60%" src="{{ url('/vendor/acelle-cashier/image/razorpay.png') }}" />
                </div>
            </div>
            <div class="col-md-4 mt-40 pd-60">
                <label>{{ $newPlan->getBillableName() }}</label>  
                <h2 class="mb-40">{{ $newPlan->getBillableFormattedPrice() }}</h2>                   
    
                <p>{!! trans('cashier::messages.razorpay.change_plan.intro', [
                    'plan' => $subscription->plan->getBillableName(),
                    'new_plan' => $newPlan->getBillableName(),
                ]) !!}</p>   
                
                <ul class="dotted-list topborder section mb-4">
                    <li>
                        <div class="unit size1of2">
                            {{ trans('cashier::messages.razorpay.new_plan') }}
                        </div>
                        <div class="lastUnit size1of2">
                            <mc:flag>{{ $newPlan->getBillableName() }}</mc:flag>
                        </div>
                    </li>
                    <li>
                        <div class="unit size1of2">
                            {{ trans('cashier::messages.razorpay.next_period_day') }}
                        </div>
                        <div class="lastUnit size1of2">
                            <mc:flag>{{ $nextPeriodDay }}</mc:flag>
                        </div>
                    </li>
                    <li>
                        <div class="unit size1of2">
                            {{ trans('cashier::messages.razorpay.amount') }}
                        </div>
                        <div class="lastUnit size1of2">
                            <mc:flag>{{ $amount }}</mc:flag>
                        </div>
                    </li>
                </ul>

                @if ($newPlan->price == 0)
                    <form method="POST" action="{{ action('\Acelle\Cashier\Controllers\RazorpayController@changePlan', ['subscription_id' => $subscription->uid]) }}">
                        {{ csrf_field() }}
                        <input type='hidden' name='plan_id' value='{{ $newPlan->getBillableId() }}' />
                        
                        <button
                            class="btn btn-primary mr-10 mr-2"
                        >{{ trans('cashier::messages.razorpay.change_plan_proceed') }}</button>
                            
                        <a
                        href="{{ $return_url }}"
                            class="btn btn-secondary mr-10"
                        >{{ trans('cashier::messages.razorpay.return_back') }}</a>
                    </form>
                @else
                    <p>{!! trans('cashier::messages.razorpay.click_to_proceed', [
                        'plan' => $subscription->plan->getBillableName(),
                        'new_plan' => $newPlan->getBillableName(),
                    ]) !!}</p>  

                    <a href="javascript:;" class="btn btn-secondary" onclick="$('.razorpay-payment-button').click()">
                        {{ trans('cashier::messages.razorpay.pay_with_razorpay') }}
                    </a>

                    <div class="hide" style="display:none">
                        <form action="{{ action('\Acelle\Cashier\Controllers\RazorpayController@changePlan', [
                        '_token' => csrf_token(),
                        'subscription_id' => $subscription->uid,
                        'plan_id' => $newPlan->getBillableId(),
                    ]) }}" method="POST">

                            <script
                                src="https://checkout.razorpay.com/v1/checkout.js"
                                data-key="{{ $service->key_id }}" // Enter the Test API Key ID generated from Dashboard → Settings → API Keys
                                data-amount="{{ $service->convertPrice($newPlan->getBillableAmount(), $newPlan->getBillableCurrency()) }}" // Amount is in currency subunits. Hence, 29935 refers to 29935 paise or ₹299.35.
                                data-currency="{{ $newPlan->getBillableCurrency() }}" //You can accept international payments by changing the currency code. Contact our Support Team to enable International for your account
                                data-order_id="{{ $order["id"] }}" //Replace with the order_id generated by you in the backend.
                                data-buttontext="{{ trans('cashier::messages.razorpay.pay_with_razorpay') }}"
                                data-name="{{ $newPlan->getBillableName() }}"
                                data-description="{{ $newPlan->description }}"
                                data-image="{{ \Acelle\Model\Setting::get('site_logo_small') ? action('SettingController@file', \Acelle\Model\Setting::get('site_logo_small')) : URL::asset('images/default_site_logo_small_' . (Auth::user()->customer->getColorScheme() == "white" ? "dark" : "light") . '.png') }}"
                                data-prefill.email="{{ $subscription->user->getBillableEmail() }}"
                                data-theme.color="#F37254"
                                data-customer_id="{{ $customer["id"] }}"
                                data-save="1"
                            ></script>
                            <input type="hidden" custom="Hidden Element" name="hidden">
                        </form>
                    </div>
                    
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