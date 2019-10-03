<html lang="en">
    <head>
        <title>{{ trans('cashier::messages.stripe.checkout.page_title') }}</title>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
        <script type="text/javascript" src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
            
        <style>
            .mb-10 {
                margin-bottom: 10px;
            }
            .mb-40 {
                margin-bottom: 40px;
            }
            .mb-20 {
                margin-bottom: 20px;
            }
            .mt-40 {
                margin-top: 40px;
            }
            .pd-30 {
                padding: 30px;
            }
            .pd-60 {
                padding: 60px;
            }
            .full-width {
                width: 100%;
            }
            
            ul.dotted-list {
                list-style: none;
                padding-left: 0;
            }
            .dotted-list > li {
                line-height: 24px;
                padding: 12px 0 11px;
                border-bottom: 1px dotted #e0e0e0;
                display: list-item;
            }
            .dotted-list>li>div {
                padding: 0;
                display: block;
            }
            .topborder>li:first-child {
                border-top: 1px dotted #e0e0e0;
            }
            .size1of2 {
                width: 50%;
                float: left;
            }
            .size1of3 {
                width: 33.3%;
                float: left;
            }
            .size2of3 {
                width: 66.6%;
                float: left;
            }
            .lastUnit, .lastGroup {
                float: none;
                width: auto;
            }
            .lastUnit, .unit {
                padding-left: 15px;
                padding-right: 15px;
            }
            .sub-section {
                margin-bottom: 60px;
            }
            body {
                background: #f9f9f9;
            }
            body:before {
                height: 100%;
                width: 50%;
                position: fixed;
                content: " ";
                top: 0;
                right: 0;
                background: #fff;
                -webkit-animation: enter-background-shadow .6s;
                animation: enter-background-shadow .6s;
                -webkit-animation-fill-mode: both;
                animation-fill-mode: both;
                -webkit-transform-origin: right;
                -ms-transform-origin: right;
                transform-origin: right;
            }
        </style>
    </head>
    
    <body>
        <div class="main-container row mt-40">
            <div class="col-md-2"></div>
            <div class="col-md-4 mt-40 pd-60">
                <label class="text-semibold text-muted mb-20 mt-0">
                    <strong>
                        {{ trans('cashier::messages.stripe.checkout_with_stripe') }}
                    </strong>
                </label>
                <img class="rounded" width="100%" src="{{ url('/vendor/acelle-cashier/image/stripe.png') }}" />
            </div>
            <div class="col-md-4 mt-40 pd-60">                
                <label>{{ $subscription->plan->getBillableName() }}</label>  
                <h2 class="mb-40">{{ $subscription->plan->getBillableFormattedPrice() }}</h2>
                    
                @if ($gatewayService->getCardInformation($subscription->user) !== NULL)
                    <p>{!! trans('cashier::messages.stripe.click_or_choose_card_bellow_to_pay', [
                        'plan' => $subscription->plan->getBillableName(),
                        'price' => $subscription->plan->getBillableFormattedPrice(),
                    ]) !!}</p>
                        
                    <div class="sub-section">
                        <h4 class="text-semibold mb-3 mt-4">{!! trans('cashier::messages.stripe.card_list') !!}</h4>
                        <ul class="dotted-list topborder section mb-4">
                            <li>
                                <div class="unit size1of2">
                                    {{ trans('messages.card.holder') }}
                                </div>
                                <div class="lastUnit size1of2">
                                    <mc:flag>{{ $gatewayService->getCardInformation($subscription->user)->name }}</mc:flag>
                                </div>
                            </li>
                            <li>
                                <div class="unit size1of2">
                                    {{ trans('messages.card.last4') }}
                                </div>
                                <div class="lastUnit size1of2">
                                    <mc:flag>{{ $gatewayService->getCardInformation($subscription->user)->last4 }}</mc:flag>
                                </div>
                            </li>
                        </ul>
                        
                        <a href="{{ action('\Acelle\Cashier\Controllers\StripeController@charge', [
                            'subscription_id' => $subscription->uid,
                        ]) }}" class="btn btn-primary mr-2">{{ trans('messages.subscription.pay_with_this_card') }}</a>
                        <a href="javascript:;" class="btn btn-secondary" onclick="$('#stripe_button button').click()">{{ trans('messages.subscription.pay_with_new_card') }}</a>
                    </div>
                @else
                    <p>{!! trans('cashier::messages.stripe.click_bellow_to_pay', [
                        'plan' => $subscription->plan->getBillableName(),
                        'price' => $subscription->plan->getBillableFormattedPrice(),
                    ]) !!}</p>
                    <hr />
                    <a href="javascript:;" class="btn btn-secondary full-width" onclick="$('#stripe_button button').click()">{{ trans('messages.subscription.pay_with_stripe') }}</a>
                @endif
                
                <form method="POST" action="{{ action('\Acelle\Cashier\Controllers\StripeController@cancelNow', ['subscription_id' => $subscription->uid]) }}">
                    {{ csrf_field() }}
                    
                    <a href="javascript:;" onclick="$(this).closest('form').submit()"
                        class="text-muted" style="font-size: 12px; text-decoration: underline"
                    >{{ trans('cashier::messages.stripe.cancel_new_subscription') }}</a>
                </form>
                
                <form id="stripe_button" style="display: none" action="{{ action('\Acelle\Cashier\Controllers\StripeController@updateCard', [
                    '_token' => csrf_token(),
                    'subscription_id' => $subscription->uid,
                ]) }}" method="POST">
                    <script
                      src="https://checkout.stripe.com/checkout.js" class="stripe-button"
                      data-key="{{ $gatewayService->publishableKey }}"
                      data-amount="{{ $subscription->plan->stripePrice() }}"
                      data-currency="{{ $subscription->plan->currency->code }}"
                      data-name="{{ \Acelle\Model\Setting::get('site_name') }}"
                      data-email="{{ $subscription->user->getBillableEmail() }}"
                      data-description="{{ \Acelle\Model\Setting::get('site_description') }}"
                      data-image="https://stripe.com/img/documentation/checkout/marketplace.png"
                      data-locale="{{ language_code() }}"
                      data-zip-code="true"
                      data-label="{{ trans('messages.pay_with_strip_label_button') }}">
                    </script>
                </form>
            </div>
            <div class="col-md-2"></div>
        </div>
        <br />
        <br />
        <br />
    </body>
</html>