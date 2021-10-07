<html lang="en">
    <head>
        <title>{{ trans('cashier::messages.stripe') }}</title>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
        <script type="text/javascript" src="https://code.jquery.com/jquery-3.4.1.min.js"></script>            
        <link rel="stylesheet" href="{{ \Acelle\Cashier\Cashier::public_url('/vendor/acelle-cashier/css/main.css') }}">
        <meta name="viewport" content="width=device-width, initial-scale=1" />

        @include('layouts.core._includes')

        @include('layouts.core._script_vars')

        <script src="https://js.stripe.com/v3/"></script>
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

                @if ($paymentMethod != null)
                    <div class="sub-section">

                        <h4 class="fw-600 mb-3 mt-0">{!! trans('cashier::messages.stripe.current_card') !!}</h4>
                        
                        <ul class="dotted-list topborder section">
                            <li>
                                <div class="unit size1of2">
                                    <strong>{{ trans('cashier::messages.card.brand') }}</strong>
                                </div>
                                <div class="lastUnit size1of2">
                                    <mc:flag><strong>{{ $paymentMethod->card->brand }}</strong></mc:flag>
                                </div>
                            </li>
                            <li class="selfclear">
                                <div class="unit size1of2">
                                    <strong>{{ trans('cashier::messages.card.last4') }}</strong>
                                </div>
                                <div class="lastUnit size1of2">
                                    <mc:flag><strong>{{ $paymentMethod->card->last4 }}</strong></mc:flag>
                                </div>
                            </li>
                        </ul>
                        
                        <form method="POST" action="{{ action("\Acelle\Cashier\Controllers\StripeController@checkout", [
                            'invoice_uid' => $invoice->uid,
                        ]) }}">
                            {{ csrf_field() }}
                            <input type="hidden" name="current_card" value="yes" />
                            <button  id="payWithCurrentCard" type="submit" class="mt-2 btn btn-secondary">{{ trans('cashier::messages.stripe.pay_with_this_card') }}</button>
                        </form>
                    </div>
                @endif
                
                <div class="sub-section">

                    <h4 class="fw-600 mb-3 mt-0">{!! trans('cashier::messages.stripe.new_card') !!}</h4>
                    <p>{!! trans('cashier::messages.stripe.new_card.intro') !!}</p>
                    
                    <div id="card-element" class="border p-3 rounded">
                        <!-- Elements will create input elements here -->
                    </div>
                        
                    <!-- We'll put the error messages in this element -->
                    <div id="card-errors" role="alert" class="text-danger small"></div>
                    
                    <button id="submit" class="mt-4 btn btn-secondary">{{ trans('cashier::messages.stripe.pay') }}</button>

                </div>

                <a
                    href="{{ \Acelle\Cashier\Cashier::lr_action('SubscriptionController@index') }}"
                    class="text-muted mt-4" style="text-decoration: underline; display: block"
                >{{ trans('cashier::messages.stripe.return_back') }}</a>
                
            </div>
            <div class="col-md-2"></div>
        </div>
        <br />
        <br />
        <br />
        <script>
            // Set your publishable key: remember to change this to your live publishable key in production
            // See your keys here: https://dashboard.stripe.com/apikeys
            var stripe = Stripe('{{ $service->getPublishableKey() }}');
            var elements = stripe.elements();

            // Set up Stripe.js and Elements to use in checkout form
            var style = {
                base: {
                    color: "#32325d",
                }
            };

            var card = elements.create("card", { style: style });
            card.mount("#card-element");

            card.on('change', function(event) {
                var displayError = document.getElementById('card-errors');
                if (event.error) {
                    displayError.textContent = event.error.message;
                } else {
                    displayError.textContent = '';
                }
            });

            $('#submit').on('click', function() {
                addButtonMask($(this));
                stripe.confirmCardPayment('{{ $clientSecret }}', {
                    payment_method: {
                        card: card,
                        billing_details: {
                            name: '{{ $invoice->getBillingName() }}',
                            "address": {
                            "city": null,
                                "country": '{{ $invoice->billingCountry ? $invoice->billingCountry->code : '' }}',
                                "line1": '{{ $invoice->billing_address }}',
                                "line2": null,
                                "postal_code": null,
                                "state": null
                            },
                            "email": '{{ $invoice->billing_email }}',
                            "phone": '{{ $invoice->billing_phone }}'
                        }
                    },
                    setup_future_usage: 'off_session'
                }).then(function(result) {
                    if (result.error) {
                        // Show error to your customer
                        new Dialog('alert', {
                            message: result.error.message
                        });
                        removeButtonMask($('#submit'));
                    } else {
                        if (result.paymentIntent.status === 'succeeded') {
                            // Show a success message to your customer
                            // There's a risk of the customer closing the window before callback execution
                            // Set up a webhook or plugin to listen for the payment_intent.succeeded event
                            // to save the card to a Customer

                            // The PaymentMethod ID can be found on result.paymentIntent.payment_method
                            // console.log(result.paymentIntent);

                            addMaskLoading(`{!! trans('cashier::messages.stripe.checkout.processing_payment.intro') !!}`);

                            // copy
                            $.ajax({
                                url: '{{ action("\Acelle\Cashier\Controllers\StripeController@checkout", [
                                    'invoice_uid' => $invoice->uid,
                                ]) }}',
                                type: 'POST',
                                data: {
                                    _token: '{{ csrf_token() }}',
                                    payment_method_id: result.paymentIntent.payment_method,
                                }
                            }).done(function(response) {
                                window.location = '{{ action('SubscriptionController@index') }}';
                            });
            
                        }
                    }
                });
            });
            
            $('#payWithCurrentCard').on('click', function() {
                addMaskLoading(`{!! trans('cashier::messages.stripe.checkout.processing_payment.intro') !!}`);
            });
        </script>
    </body>
</html>