<html lang="en">
    <head>
        <title>{{ trans('cashier::messages.stripe.checkout.page_title') }}</title>
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
                        {{ trans('cashier::messages.stripe.checkout_with_stripe') }}
                    </strong>
                </label>
                <img class="rounded" width="100%" src="{{ url('/vendor/acelle-cashier/image/stripe.png') }}" />
            </div>
            <div class="col-md-4 mt-40 pd-60">                
                <label>{{ $subscription->plan->getBillableName() }}</label>  
                <h2 class="mb-40">{{ $subscription->plan->getBillableFormattedPrice() }}</h2>
                
                @if ($service->getCardInformation($subscription->user) !== NULL)
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
                                    <mc:flag>{{ $service->getCardInformation($subscription->user)->name }}</mc:flag>
                                </div>
                            </li>
                            <li>
                                <div class="unit size1of2">
                                    {{ trans('messages.card.last4') }}
                                </div>
                                <div class="lastUnit size1of2">
                                    <mc:flag>{{ $service->getCardInformation($subscription->user)->last4 }}</mc:flag>
                                </div>
                            </li>
                        </ul>
                        
                        <a href="{{ action('\Acelle\Cashier\Controllers\StripeController@charge', [
                            'subscription_id' => $subscription->uid,
                        ]) }}" class="btn btn-primary mr-2">{{ trans('cashier::messages.stripe.pay_with_this_card') }}</a>
                        <a href="javascript:;" class="btn btn-secondary" onclick="$('#stripe_button button').click()">{{ trans('cashier::messages.stripe.pay_with_new_card') }}</a>
                    </div>
                @else
                    <p>{!! trans('cashier::messages.stripe.click_bellow_to_pay', [
                        'plan' => $subscription->plan->getBillableName(),
                        'price' => $subscription->plan->getBillableFormattedPrice(),
                    ]) !!}</p>
                    <hr />
                    <a href="javascript:;" class="btn btn-secondary full-width" onclick="$('#stripe_button button').click()">{{ trans('cashier::messages.stripe.pay') }}</a>
                @endif

                <form method="POST" action="{{ action('\Acelle\Cashier\Controllers\StripeController@cancelNow', ['subscription_id' => $subscription->uid]) }}">
                    {{ csrf_field() }}
                    
                    <a href="javascript:;" onclick="$(this).closest('form').submit()"
                        class="text-muted mt-4" style="font-size: 12px; text-decoration: underline; display: block"
                    >{{ trans('cashier::messages.stripe.cancel_new_subscription') }}</a>
                </form>
                
                <form id="stripe_button" style="display: none" action="{{ action('\Acelle\Cashier\Controllers\StripeController@updateCard', [
                    '_token' => csrf_token(),
                    'subscription_id' => $subscription->uid,
                ]) }}" method="POST">
                    <input type="hidden" name="redirect" value="{{ action('\Acelle\Cashier\Controllers\StripeController@charge', [
                        'subscription_id' => $subscription->uid,
                    ]) }}" />

                    <script
                    src="https://checkout.stripe.com/checkout.js" class="stripe-button"
                    data-key="{{ $service->publishableKey }}"
                    data-amount="{{ $service->convertPrice($subscription->plan->getBillableAmount(), $subscription->plan->getBillableCurrency()) }}"
                    data-currency="{{ $subscription->plan->getBillableCurrency() }}"
                    data-name="{{ \Acelle\Model\Setting::get('site_name') }}"
                    data-email="{{ $subscription->user->getBillableEmail() }}"
                    data-description="{{ \Acelle\Model\Setting::get('site_description') }}"
                    data-image="https://stripe.com/img/documentation/checkout/marketplace.png"
                    data-locale="{{ language_code() }}"
                    data-zip-code="true"
                    data-billing-address="{{ $service->billing_address_required == 'yes' ? 'true' : 'false' }}"
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