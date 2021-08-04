<html lang="en">
    <head>
        <title>{{ trans('cashier::messages.braintree.checkout.page_title') }}</title>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
        <script type="text/javascript" src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
        <link rel="stylesheet" href="{{ \Acelle\Cashier\Cashier::public_url('/vendor/acelle-cashier/css/main.css') }}">

        <style>
            .braintree-placeholder {display:none}
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
                <img class="rounded" width="100%" src="{{ \Acelle\Cashier\Cashier::public_url('/vendor/acelle-cashier/image/braintree.png') }}" />
            </div>
            <div class="col-md-4 mt-40 pd-60">
                
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
                        
                        <form
                            action="{{ \Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\BraintreeController@autoBillingDataUpdate') }}" method="POST">
                            {{ csrf_field() }}
                            <input type="hidden" name="return_url" value="{{ request()->return_url }}" />
                            <input type="submit" name="use_current_card" style="width: 100%;" href="{{ '' }}" class="btn btn-primary mr-2"
                                value="{{ trans('cashier::messages.braintree.use_this_card') }}" />
                        </form>
                    </div>
                @endif

                <h4 class="text-semibold mt-4 mb-3">{!! trans('cashier::messages.braintree.new_card') !!}</h4>
                    
                <script src="https://js.braintreegateway.com/web/dropin/1.6.1/js/dropin.js"></script>
                <div id="dropin-container"></div>
                
                <a style="width: 100%" id="submit-button" href="javascript:;" class="btn btn-secondary full-width mt-10">
                    {{ trans('cashier::messages.braintree.use_new_card') }}
                </a>
                    
                <form id="updateCard" style="display: none"
                    action="{{ \Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\BraintreeController@autoBillingDataUpdate') }}" method="POST">
                        {{ csrf_field() }}
                        <input type="hidden" name="return_url" value="{{ request()->return_url }}" />
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