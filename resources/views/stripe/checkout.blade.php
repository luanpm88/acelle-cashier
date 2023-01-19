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
        <div class="main-container row mt-5">
            <div class="col-md-2"></div>
            <div class="col-md-4 mt-40 pd-60">
                <label class="text-semibold text-muted mb-20 mt-0">
                    <strong>
                        {{ trans('cashier::messages.stripe') }}
                    </strong>
                </label>
                <div>
                    <svg style="width:200px;height:100px;" viewBox="0 0 60 25" xmlns="http://www.w3.org/2000/svg" width="60" height="25" class="UserLogo variant-- "><title>Stripe logo</title><path fill="var(--userLogoColor, #0A2540)" d="M59.64 14.28h-8.06c.19 1.93 1.6 2.55 3.2 2.55 1.64 0 2.96-.37 4.05-.95v3.32a8.33 8.33 0 0 1-4.56 1.1c-4.01 0-6.83-2.5-6.83-7.48 0-4.19 2.39-7.52 6.3-7.52 3.92 0 5.96 3.28 5.96 7.5 0 .4-.04 1.26-.06 1.48zm-5.92-5.62c-1.03 0-2.17.73-2.17 2.58h4.25c0-1.85-1.07-2.58-2.08-2.58zM40.95 20.3c-1.44 0-2.32-.6-2.9-1.04l-.02 4.63-4.12.87V5.57h3.76l.08 1.02a4.7 4.7 0 0 1 3.23-1.29c2.9 0 5.62 2.6 5.62 7.4 0 5.23-2.7 7.6-5.65 7.6zM40 8.95c-.95 0-1.54.34-1.97.81l.02 6.12c.4.44.98.78 1.95.78 1.52 0 2.54-1.65 2.54-3.87 0-2.15-1.04-3.84-2.54-3.84zM28.24 5.57h4.13v14.44h-4.13V5.57zm0-4.7L32.37 0v3.36l-4.13.88V.88zm-4.32 9.35v9.79H19.8V5.57h3.7l.12 1.22c1-1.77 3.07-1.41 3.62-1.22v3.79c-.52-.17-2.29-.43-3.32.86zm-8.55 4.72c0 2.43 2.6 1.68 3.12 1.46v3.36c-.55.3-1.54.54-2.89.54a4.15 4.15 0 0 1-4.27-4.24l.01-13.17 4.02-.86v3.54h3.14V9.1h-3.13v5.85zm-4.91.7c0 2.97-2.31 4.66-5.73 4.66a11.2 11.2 0 0 1-4.46-.93v-3.93c1.38.75 3.1 1.31 4.46 1.31.92 0 1.53-.24 1.53-1C6.26 13.77 0 14.51 0 9.95 0 7.04 2.28 5.3 5.62 5.3c1.36 0 2.72.2 4.09.75v3.88a9.23 9.23 0 0 0-4.1-1.06c-.86 0-1.44.25-1.44.9 0 1.85 6.29.97 6.29 5.88z" fill-rule="evenodd"></path></svg>
                </div>
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
                                "country": '{{ $invoice->getBillingCountryCode() }}',
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
                                window.location = '{{ Billing::getReturnUrl() }}';
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