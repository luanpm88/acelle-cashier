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
    
    <body class="pt-0">
        <div class="px-5 py-3" style="max-width:1200px;margin:auto;">
            <div class="row">
                <!-- Left side: Invoice details -->
                <div class="col-md-6 bg-light shadow-sm rounded">
                    <div class="px-4 py-5">
                        <div class="mb-5">
                            <span class="me-2">
                                <svg width="50" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1" id="Layer_1" x="0px" y="0px" style="enable-background:new 0 0 468 222.5;" xml:space="preserve" viewBox="54 36 360.02 149.84"> <style type="text/css"> 	.st0{fill-rule:evenodd;clip-rule:evenodd;fill:#635BFF;} </style> <g> 	<path class="st0" d="M414,113.4c0-25.6-12.4-45.8-36.1-45.8c-23.8,0-38.2,20.2-38.2,45.6c0,30.1,17,45.3,41.4,45.3   c11.9,0,20.9-2.7,27.7-6.5v-20c-6.8,3.4-14.6,5.5-24.5,5.5c-9.7,0-18.3-3.4-19.4-15.2h48.9C413.8,121,414,115.8,414,113.4z    M364.6,103.9c0-11.3,6.9-16,13.2-16c6.1,0,12.6,4.7,12.6,16H364.6z"/> 	<path class="st0" d="M301.1,67.6c-9.8,0-16.1,4.6-19.6,7.8l-1.3-6.2h-22v116.6l25-5.3l0.1-28.3c3.6,2.6,8.9,6.3,17.7,6.3   c17.9,0,34.2-14.4,34.2-46.1C335.1,83.4,318.6,67.6,301.1,67.6z M295.1,136.5c-5.9,0-9.4-2.1-11.8-4.7l-0.1-37.1   c2.6-2.9,6.2-4.9,11.9-4.9c9.1,0,15.4,10.2,15.4,23.3C310.5,126.5,304.3,136.5,295.1,136.5z"/> 	<polygon class="st0" points="223.8,61.7 248.9,56.3 248.9,36 223.8,41.3  "/> 	<rect x="223.8" y="69.3" class="st0" width="25.1" height="87.5"/> 	<path class="st0" d="M196.9,76.7l-1.6-7.4h-21.6v87.5h25V97.5c5.9-7.7,15.9-6.3,19-5.2v-23C214.5,68.1,202.8,65.9,196.9,76.7z"/> 	<path class="st0" d="M146.9,47.6l-24.4,5.2l-0.1,80.1c0,14.8,11.1,25.7,25.9,25.7c8.2,0,14.2-1.5,17.5-3.3V135   c-3.2,1.3-19,5.9-19-8.9V90.6h19V69.3h-19L146.9,47.6z"/> 	<path class="st0" d="M79.3,94.7c0-3.9,3.2-5.4,8.5-5.4c7.6,0,17.2,2.3,24.8,6.4V72.2c-8.3-3.3-16.5-4.6-24.8-4.6   C67.5,67.6,54,78.2,54,95.9c0,27.6,38,23.2,38,35.1c0,4.6-4,6.1-9.6,6.1c-8.3,0-18.9-3.4-27.3-8v23.8c9.3,4,18.7,5.7,27.3,5.7   c20.8,0,35.1-10.3,35.1-28.2C117.4,100.6,79.3,105.9,79.3,94.7z"/> </g> </svg>
                            </span>
                            <span>
                                <span class="badge badge-light bg-dark">Secured Transaction</span>
                            </span>
                        </div>
                        <div class="mb-4">
                            <label class="">Total amount:</label>
                            <h2>{{ number_format($invoice->total(), 2) }}  ({{ $invoice->getCurrencyCode() }})</h2>
                        </div>
                        <label class="">Items:</label>
                        <table class="w-100">
                            <tbody>
                                @foreach ($invoice->invoiceItems as $invoiceItem)
                                    <tr>
                                        <td>
                                            @if($invoiceItem->image_url)
                                                <div class="me-3 d-inline-block shadow-sm" style="background-color: #F0F0F0;border-radius:3px;overflow:hidden;">
                                                    <img src="{{ $invoiceItem->image_url }}" alt="" style="height:40px;width:40px;object-fit:contain;">
                                                </div>
                                            @endif
                                        </td>
                                        <td class="py-3 pe-4" style="width: 70%;">
                                            <p class="fw-semibold mb-1">{!! $invoiceItem->title !!}</p>
                                            <p class="mb-0 small">{{ trans('cashier::messages.quantity') }}: <strong>1</strong></p>
                                        </td>
                                        <td class="text-end py-3">
                                            <span class="text-bold">{{ number_format($invoiceItem->amount, 2) }}</span><br>
                                            <span class="text-muted small">({{ $invoice->getCurrencyCode() }})</span>
                                        </td>
                                    </tr>
                                @endforeach

                                <tr>
                                    <td>
                                        
                                    </td>
                                    <td valign="top" class="pb-2 pe-4 border-bottom pt-5" style="width: 70%;">
                                        <p class="fw-semibold mb-1 small">Subtotal</p>
                                    </td>
                                    <td class="text-end pb-2 border-bottom pt-5">
                                        <span class="text-bold">
                                            {{ number_format($invoice->total(), 2) }}
                                            ({{ $invoice->getCurrencyCode() }})
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        
                                    </td>
                                    <td class="py-3 pe-4 border-bottom small" style="width: 70%;">
                                        <p class="mb-1 text-muted2">Payment Transaction Fee</p>
                                        <p class="mb-1 text-muted2">Activate account after subscribing to the plan</p>
                                    </td>
                                    <td valign="top" class="text-end py-3 border-bottom text-muted2"><span class="">{{ number_format($invoice->total(), 2) }}  ({{ $invoice->getCurrencyCode() }})</span></td>
                                </tr>
                                <tr>
                                    <td>
                                        
                                    </td>
                                    <td valign="top" class="py-3 pe-4" style="width: 70%;">
                                        <p class="fw-semibold mb-1 small">{{ trans('cashier::messages.total_due') }}</p>
                                    </td>
                                    <td class="text-end py-3"><span class="text-bold text-nowrap">{{ number_format($invoice->total(), 2) }}  ({{ $invoice->getCurrencyCode() }})</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Right side: Payment form -->
                <div class="col-md-6">
                    <div class="p-5">
                        <h2 class="mb-4">{{ trans('cashier::messages.stripe.new_card') }}</h2>
                        <p class="text-muted">{{ trans('cashier::messages.stripe.new_card.intro2') }}</p>
                        <div id="card-element" class="form-control py-3 shadow-sm" style="height: auto!important;"></div>
                        <div id="card-errors" class="text-danger mt-2" role="alert"></div>
                        <button id="submit" class="btn btn-dark w-100 mt-4 py-2 fs-5">
                            {{ trans('cashier::messages.stripe.pay') }} {{ number_format($invoice->total(), 2) }} ({{ $invoice->getCurrencyCode() }})
                        </button>

                    </div>
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