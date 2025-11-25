<html lang="en">
    <head>
        <title>{{ trans('cashier::messages.stripe.checkout.page_title') }}</title>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
        <script type="text/javascript" src="https://code.jquery.com/jquery-3.4.1.min.js"></script>            
        <link rel="stylesheet" href="{{ \Acelle\Cashier\Cashier::public_url('/vendor/acelle-cashier/css/main.css') }}">

        <meta name="viewport" content="width=device-width, initial-scale=1" />

        @include('layouts.core._includes')

        @include('layouts.core._script_vars')

        <script src="https://js.stripe.com/v3/"></script>
    </head>
    
    <body>
        <div class="px-4">
            <div class="row">
                <!-- Left side: Invoice details -->
                <div class="col-md-6">
                    <h2 class="mb-4">{{ trans('cashier::messages.pay_invoice') }}</h2>
                    <table class="w-100">
                        <tbody>
                            <tr>
                                <td>
                                    <div class="p-2 rounded me-2 d-inline-block" style="background-color: #BCA38B">
                                        <svg style="height:40px;width:40px;" xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="http://www.w3.org/2000/svg" width="24px" fill="#FFFFFF"><path d="M160-288.46v76.15q0 4.62 3.85 8.46 3.84 3.85 8.46 3.85h615.38q4.62 0 8.46-3.85 3.85-3.84 3.85-8.46v-76.15H160ZM300-700v-72.31q0-30.3 21-51.3 21-21 51.31-21h215.38q30.31 0 51.31 21 21 21 21 51.3V-700h127.69Q818-700 839-679q21 21 21 51.31v415.38Q860-182 839-161q-21 21-51.31 21H172.31Q142-140 121-161q-21-21-21-51.31v-415.38Q100-658 121-679q21-21 51.31-21H300ZM160-391.54h640v-236.15q0-4.62-3.85-8.46-3.84-3.85-8.46-3.85H660v42.31q0 12.77-8.62 21.38-8.61 8.62-21.38 8.62t-21.38-8.62q-8.62-8.61-8.62-21.38V-640H360v42.31q0 12.77-8.62 21.38-8.61 8.62-21.38 8.62t-21.38-8.62q-8.62-8.61-8.62-21.38V-640H172.31q-4.62 0-8.46 3.85-3.85 3.84-3.85 8.46v236.15ZM360-700h240v-72.31q0-4.61-3.85-8.46-3.84-3.84-8.46-3.84H372.31q-4.62 0-8.46 3.84-3.85 3.85-3.85 8.46V-700ZM160-212.31V-640v72.31V-640v72.31V-640h12.31q-4.62 0-8.46 3.85-3.85 3.84-3.85 8.46v415.38q0 4.62 3.85 8.46 3.84 3.85 8.46 3.85H160v-12.31Z"/></svg>
                                    </div>
                                </td>
                                <td class="py-2 pe-4" style="width: 70%;">
                                    <p class="fw-bold mb-1">{!! $invoice->description !!}</p>
                                    <p class="mb-0">{{ trans('cashier::messages.quantity') }}: 1</p>
                                </td>
                                <td class="text-end py-2"><span class="text-bold display-4">{{ number_format($invoice->total(), 2) }}</span><br>({{ $invoice->getCurrencyCode() }})</td>
                            </tr>
                        </tbody>
                    </table>
                    <hr>
                    <p class="text-muted text-end">{{ trans('cashier::messages.total_due') }}</p>
                    <h1 class="fw-bold text-end">{{ number_format($invoice->total(), 2) }} ({{ $invoice->getCurrencyCode() }})</h1>
                </div>

                <!-- Right side: Payment form -->
                <div class="col-md-6">
                    <h2 class="mb-4">{{ trans('cashier::messages.stripe.new_card') }}</h2>
                    <p class="text-muted">{{ trans('cashier::messages.stripe.new_card.intro2') }}</p>
                    <div id="card-element" class="form-control py-3 shadow-sm" style="height: auto!important;"></div>
                    <div id="card-errors" class="text-danger mt-2" role="alert"></div>
                    <button id="submit" class="btn btn-dark w-100 mt-4 py-3 fs-5">
                        {{ trans('cashier::messages.stripe.pay') }} {{ number_format($invoice->total(), 2) }} ({{ $invoice->getCurrencyCode() }})
                    </button>
                </div>
            </div>
        </div>
        <br />
        <br />
        <br />
        <script>
            // Set your publishable key: remember to change this to your live publishable key in production
            // See your keys here: https://dashboard.stripe.com/apikeys
            var stripe = Stripe('{{ $publishableKey }}');
            var elements = stripe.elements();

            // Set up Stripe.js and Elements to use in checkout form
            var style = {
                base: {
                    color: "#32325d",
                }
            };

            // var card = elements.create("card", { style: style, hidePostalCode: true });
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
                // Build address object and only include country if not empty
                var address = {
                    city: null,
                    line1: '{{ $invoice->billing_address }}',
                    line2: null,
                    postal_code: null,
                    state: null
                };
                var countryCode = '{{ $invoice->getBillingCountryCode() }}';
                if (countryCode) {
                    address.country = countryCode;
                }
                stripe.confirmCardSetup('{{ $clientSecret }}', {
                    payment_method: {
                        card: card,
                        billing_details: {
                            name: '{{ $invoice->getBillingName() }}',
                            address: address,
                            email: '{{ $invoice->billing_email }}',
                            phone: '{{ $invoice->billing_phone }}'
                        }
                    }
                }).then(function(result) {
                    if (result.error) {
                        new Dialog('alert', {
                            message: result.error.message
                        });
                        removeButtonMask($('#submit'));
                    } else {
                        if (result.setupIntent.status === 'succeeded') {
                            addMaskLoading(`{!! trans('cashier::messages.stripe.checkout.processing_payment.intro') !!}`);
                            $.ajax({
                                url: '{{ action("\Acelle\Cashier\Controllers\StripeController@checkout", [
                                    'invoice_uid' => $invoice->uid,
                                    'payment_gateway_id' => $paymentGateway->uid,
                                ]) }}',
                                type: 'POST',
                                data: {
                                    _token: '{{ csrf_token() }}',
                                    payment_method_id: result.setupIntent.payment_method,
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