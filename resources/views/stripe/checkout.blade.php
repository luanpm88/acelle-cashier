<html lang="en">
    <head>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
        <script type="text/javascript" src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
            
        <style>
            .mb-10 {
                margin-bottom: 10px;
            }
            .mb-40 {
                margin-bottom: 40px;
            }
            
            .mt-40 {
                margin-top: 40px;
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
        </style>
    </head>
    
    <body>
        <div class="row mt-40">
            <div class="col-md-2"></div>
            <div class="col-md-3 text-center mt-40">
                <img width="100%" src="{{ url('/vendor/acelle-cashier/image/stripe.png') }}" />
            </div>
            <div class="col-md-5 mt-40">
                <h1 class="text-semibold mb-20 mt-0">{{ trans('cashier::messages.stripe.checkout_with_stripe') }}</h1>
        
                @if ($gatewayService->getCardInformation($subscription->user) !== NULL)
                    <div class="sub-section">
                        <h4 class="text-semibold">{!! trans('cashier::messages.stripe.card_list') !!}</h4>
                        <ul class="dotted-list topborder section mb-20">
                            <li>
                                <div class="unit size1of2">
                                    <strong>{{ trans('messages.card.holder') }}</strong>
                                </div>
                                <div class="lastUnit size1of2">
                                    <mc:flag>{{ $gatewayService->getCardInformation($subscription->user)->name }}</mc:flag>
                                </div>
                            </li>
                            <li>
                                <div class="unit size1of2">
                                    <strong>{{ trans('messages.card.last4') }}</strong>
                                </div>
                                <div class="lastUnit size1of2">
                                    <mc:flag>{{ $gatewayService->getCardInformation($subscription->user)->last4 }}</mc:flag>
                                </div>
                            </li>
                        </ul>
                
                        <a href="{{ action('\Acelle\Cashier\Controllers\StripeController@charge', [
                            'subscription_id' => $subscription->uid,
                        ]) }}" class="btn btn-primary">{{ trans('messages.subscription.pay_with_this_card') }}</a>
                        <a href="javascript:;" class="btn btn-secondary" onclick="$('#stripe_button button').click()">{{ trans('messages.subscription.pay_with_new_card') }}</a>
                    </div>
                @else
                    <p>{{ trans('cashier::messages.stripe.click_bellow_to_pay') }}</p>
                    <a href="javascript:;" class="btn btn-secondary" onclick="$('#stripe_button button').click()">{{ trans('messages.subscription.pay_with_stripe') }}</a>
                @endif
                
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