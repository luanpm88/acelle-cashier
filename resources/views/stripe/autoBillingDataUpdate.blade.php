<html lang="en">
    <head>
        <title>{{ trans('cashier::messages.stripe') }}</title>
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
                        {{ trans('cashier::messages.stripe') }}
                    </strong>
                </label>
                <img class="rounded" width="100%" src="{{ \Acelle\Cashier\Cashier::public_url('/vendor/acelle-cashier/image/stripe.png') }}" />
            </div>
            <div class="col-md-4 mt-40 pd-60">
                
                <div class="sub-section">
                    @if ($cardInfo !== NULL)
                        <h4 class="text-semibold mb-3 mt-4">{!! trans('cashier::messages.stripe.card_list') !!}</h4>
                        <ul class="dotted-list topborder section mb-4">
                            <li>
                                <div class="unit size1of2">
                                    {{ trans('messages.card.holder') }}
                                </div>
                                <div class="lastUnit size1of2">
                                    <mc:flag>{{ $service->getCardInformation(request()->user()->customer)->name }}</mc:flag>
                                </div>
                            </li>
                            <li>
                                <div class="unit size1of2">
                                    {{ trans('messages.card.last4') }}
                                </div>
                                <div class="lastUnit size1of2">
                                    <mc:flag>{{ $service->getCardInformation(request()->user()->customer)->last4 }}</mc:flag>
                                </div>
                            </li>
                        </ul>

                        <form id="stripe_button"
                            action="{{ \Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\StripeController@autoBillingDataUpdate') }}" method="POST">
                            {{ csrf_field() }}
                                <input type="hidden" name="return_url" value="{{ request()->return_url }}" />
                                <input type="submit" name="use_current_card" class="btn btn-primary mr-2"
                                    value="{{ trans('cashier::messages.stripe.use_current_card') }}"
                                >
                                </button>
                                <a href="javascript:;" class="btn btn-secondary change-card-button">
                                    {{ trans('cashier::messages.stripe.change_card') }}
                                </a>
                        </form>
                    @else
                        <h4 class="text-semibold mb-3 mt-4">{!! trans('cashier::messages.stripe.click_button_to_connect') !!}</h4>
                        <p>{!! trans('cashier::messages.stripe.no_card') !!}</p>
                        <form id="stripe_button" style=""
                            action="{{ \Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\StripeController@autoBillingDataUpdate') }}" method="POST">

                                <input type="hidden" name="return_url" value="{{ request()->return_url }}" />

                                <a href="javascript:;" class="btn btn-secondary change-card-button">
                                    {{ trans('cashier::messages.stripe.add_card') }}
                                </a>
                        </form>
                    @endif
                    
                    
                </div>

                <form id="stripe_button" style="display:none"
                    action="{{ \Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\StripeController@autoBillingDataUpdate', [
                    '_token' => csrf_token(),
                ]) }}" method="POST">

                    <input type="hidden" name="return_url" value="{{ request()->return_url }}" /> 

                    <script
                    src="https://checkout.stripe.com/checkout.js" class="stripe-button"
                    data-key="{{ $service->getPublishableKey() }}"
                    data-image="https://stripe.com/img/documentation/checkout/marketplace.png"
                    data-locale="{{ language_code() }}"
                    data-zip-code="true"
                    data-label="{{ trans('messages.pay_with_strip_label_button') }}">
                    </script>
                </form>

                <a
                    href="{{ Billing::getReturnUrl() }}"
                    class="text-muted mt-4" style="text-decoration: underline; display: block"
                >{{ trans('cashier::messages.stripe.return_back') }}</a>
                
            </div>
            <div class="col-md-2"></div>
        </div>
        <br />
        <br />
        <br />
        <script>
            $('.change-card-button').click(function(e) {
                e.preventDefault();
                $('#stripe_button button.stripe-button-el').trigger('click');
            });
        </script>
    </body>
</html>