<html lang="en">
    <head>
        <title>{{ trans('cashier::messages.braintree.checkout.page_title') }}</title>
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
            .mt-20 {
                margin-top: 20px;
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
            
            .button {
  cursor: pointer;
  font-weight: 500;
  left: 3px;
  line-height: inherit;
  position: relative;
  text-decoration: none;
  text-align: center;
  border-style: solid;
  border-width: 1px;
  border-radius: 3px;
  -webkit-appearance: none;
  -moz-appearance: none;
  display: inline-block;
}

.button--small {
  padding: 10px 20px;
  font-size: 0.875rem;
}

.button--green {
  outline: none;
  background-color: #64d18a;
  border-color: #64d18a;
  color: white;
  transition: all 200ms ease;
}

.button--green:hover {
  background-color: #8bdda8;
  color: white;
}
.braintree-placeholder {
    margin-bottom: 0 !important;
}
        </style>
    </head>
    
    <body>
        <div class="main-container row mt-40">
            <div class="col-md-2"></div>
            <div class="col-md-4 mt-40 pd-60">
                <label class="text-semibold text-muted mb-20 mt-0">
                    <strong>
                        {{ trans('cashier::messages.braintree.checkout_with_braintree') }}
                    </strong>
                </label>
                <img class="rounded" width="100%" src="{{ url('/vendor/acelle-cashier/image/braintree.png') }}" />
            </div>
            <div class="col-md-4 mt-40 pd-60">                
                <label>{{ $remotePlan->name }}</label>  
                <h2 class="mb-40">{{ $remotePlan->price . $remotePlan->currencyIsoCode }}</h2>
                    
                <p class="mb-0">{!! trans('cashier::messages.braintree.click_bellow_to_pay', [
                    'plan' => $remotePlan->name,
                    'price' => $remotePlan->price . $remotePlan->currencyIsoCode,
                ]) !!}</p>
                    
                @if ($cardInfo !== NULL)
                    <div class="sub-section mb-5">
                        <h4 class="text-semibold mb-3 mt-4">{!! trans('cashier::messages.braintree.current_card') !!}</h4>
                        <ul class="dotted-list topborder section mb-4">
                            <li>
                                <div class="unit size1of2">
                                    {{ trans('messages.card.holder') }}
                                </div>
                                <div class="lastUnit size1of2">
                                    <mc:flag>{{ $cardInfo->cardType }}</mc:flag>
                                </div>
                            </li>
                            <li>
                                <div class="unit size1of2">
                                    {{ trans('messages.card.last4') }}
                                </div>
                                <div class="lastUnit size1of2">
                                    <mc:flag>{{ $cardInfo->last4 }}</mc:flag>
                                </div>
                            </li>
                        </ul>
                        
                        <a href="{{ action('\Acelle\Cashier\Controllers\BraintreeController@charge', [
                            'subscription_id' => $subscription->uid,
                        ]) }}" class="btn btn-primary mr-2">{{ trans('cashier::messages.braintree.pay_with_this_card') }}</a><!--
                        <a href="javascript:;" class="btn btn-secondary" onclick="$('#stripe_button button').click()">{{ trans('cashier::messages.braintree.pay_with_new_card') }}</a>-->
                    </div>
                                       
                @endif
                
                <h4 class="text-semibold mt-4">{!! trans('cashier::messages.braintree.pay_with_new_card') !!}</h4>
                    
                <script src="https://js.braintreegateway.com/web/dropin/1.6.1/js/dropin.js"></script>
                <div id="dropin-container"></div>
                
                <a id="submit-button" href="javascript:;" class="btn btn-secondary full-width mt-10">{{ trans('cashier::messages.braintree.pay') }}</a>
                
                <form method="POST" action="{{ action('\Acelle\Cashier\Controllers\StripeController@cancelNow', ['subscription_id' => $subscription->uid]) }}">
                    {{ csrf_field() }}
                    
                    <a href="javascript:;" onclick="$(this).closest('form').submit()"
                        class="text-muted mt-4" style="font-size: 12px; text-decoration: underline; display: block"
                    >{{ trans('cashier::messages.stripe.cancel_new_subscription') }}</a>
                </form>
                    
                <form id="updateCard" style="display: none" action="{{ action('\Acelle\Cashier\Controllers\BraintreeController@updateCard', [
                    'subscription_id' => $subscription->uid,
                ]) }}" method="POST">
                    {{ csrf_field() }}
                    <input type="hidden" name="nonce" value="" />
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
                        });
                      })
                    });
                </script>
                    
            </div>
            <div class="col-md-2"></div>
        </div>
        <br />
        <br />
        <br />
    </body>
</html>