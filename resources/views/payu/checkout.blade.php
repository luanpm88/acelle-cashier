<html lang="en">
    <head>
        <title>{{ trans('cashier::messages.payu.checkout.page_title') }}</title>
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
                        {{ trans('cashier::messages.payu.checkout_with_payu') }}
                    </strong>
                </label>
                <img class="rounded" width="100%" src="{{ url('/vendor/acelle-cashier/image/payu.png') }}" />
            </div>
            <div class="col-md-4 mt-40 pd-60">                
                <label>{{ $subscription->plan->getBillableName() }}</label>  
                <h2 class="mb-40">{{ $subscription->plan->getBillableFormattedPrice() }}</h2>
                
                @if ($service->getCardInformation($subscription->user) !== NULL)
                    <p>{!! trans('cashier::messages.payu.click_or_choose_card_bellow_to_pay', [
                        'plan' => $subscription->plan->getBillableName(),
                        'price' => $subscription->plan->getBillableFormattedPrice(),
                    ]) !!}</p>
                        
                    <div class="sub-section">
                        <h4 class="text-semibold mb-3 mt-4">{!! trans('cashier::messages.payu.card_list') !!}</h4>
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
                        
                        <a href="{{ action('\Acelle\Cashier\Controllers\PayuController@charge', [
                            'subscription_id' => $subscription->uid,
                        ]) }}" class="btn btn-primary mr-2">{{ trans('cashier::messages.payu.pay_with_this_card') }}</a>
                        <a href="javascript:;" class="btn btn-secondary" onclick="$('#stripe_button button').click()">{{ trans('cashier::messages.payu.pay_with_new_card') }}</a>
                    </div>
                @else
                    <p>{!! trans('cashier::messages.payu.click_bellow_to_pay', [
                        'plan' => $subscription->plan->getBillableName(),
                        'price' => $subscription->plan->getBillableFormattedPrice(),
                    ]) !!}</p>
                    <hr />
                    <a href="javascript:;" class="btn btn-secondary full-width" onclick="$('#pay-button').click()">{{ trans('cashier::messages.payu.pay') }}</a>
                @endif

                <form method="POST" action="{{ action('\Acelle\Cashier\Controllers\PayuController@cancelNow', ['subscription_id' => $subscription->uid]) }}">
                    {{ csrf_field() }}
                    
                    <a href="javascript:;" onclick="$(this).closest('form').submit()"
                        class="text-muted mt-4" style="font-size: 12px; text-decoration: underline; display: block"
                    >{{ trans('cashier::messages.payu.cancel_new_subscription') }}</a>
                </form>
                
                <form action="{{ action('\Acelle\Cashier\Controllers\PayuController@updateCard', [
                    '_token' => csrf_token(),
                    'subscription_id' => $subscription->uid,
                ]) }}" style="display:none" method="post">
                    <input type="hidden" name="redirect" value="{{ action('\Acelle\Cashier\Controllers\PayuController@charge', [
                        'subscription_id' => $subscription->uid,
                    ]) }}" />

                    <button id="pay-button">Pay now</button>
                </form>

                <script
                    src="https://secure.payu.com/front/widget/js/payu-bootstrap.js"
                    pay-button="#pay-button"
                    merchant-pos-id="{{ $service->client_id }}"
                    shop-name="{{ \Acelle\Model\Setting::get('site_name') }}"
                    total-amount="{{ $subscription->plan->getBillableAmount() }}"
                    currency-code="{{ $subscription->plan->getBillableCurrency() }}"
                    customer-language="{{ \Auth::user()->customer->getLanguageCode() }}"
                    store-card="true"
                    recurring-payment="true"
                    customer-email="{{ $subscription->user->getBillableEmail() }}"
                    sig="{{ $service->getSig($subscription) }}">
                </script>
            </div>
            <div class="col-md-2"></div>
        </div>
        <br />
        <br />
        <br />
    </body>
</html>