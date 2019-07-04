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
                        <h3 class="text-semibold">{!! trans('cashier::messages.stripe.card_list') !!}</h3>
                        <ul class="dotted-list topborder section mb-20">
                            <li>
                                <div class="unit size1of2">
                                    <strong>{{ trans('messages.card.holder') }}</strong>
                                </div>
                                <div class="lastUnit size1of2">
                                    <mc:flag><strong>{{ $gatewayService->getCardInformation($subscription->user)->name }}</strong></mc:flag>
                                </div>
                            </li>
                            <li>
                                <div class="unit size1of2">
                                    <strong>{{ trans('messages.card.last4') }}</strong>
                                </div>
                                <div class="lastUnit size1of2">
                                    <mc:flag><strong>{{ $gatewayService->getCardInformation($subscription->user)->last4 }}</strong></mc:flag>
                                </div>
                            </li>
                        </ul>
                
                        <a href="{{ action('\Acelle\Cashier\Controllers\StripeController@charge', [
                            'subscription_id' => $subscription->uid,
                        ]) }}" class="btn btn-mc_primary mr-10">{{ trans('messages.subscription.pay_with_this_card') }}</a>
                        <a href="javascript:;" class="btn btn-mc_default" onclick="$('#stripe_button button').click()">{{ trans('messages.subscription.pay_with_new_card') }}</a>
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