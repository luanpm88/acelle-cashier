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
        
        <script>
            addMaskLoading(`{!! trans('cashier::messages.stripe.checkout.processing_payment.intro') !!}`);

            // Set your publishable key: remember to change this to your live publishable key in production
            // See your keys here: https://dashboard.stripe.com/apikeys
            var stripe = Stripe('{{ $service->getPublishableKey() }}');
            // Pass the failed PaymentIntent to your client from your server
            stripe.confirmCardPayment('{{ $intent->client_secret }}', {
                payment_method: '{{ $intent->last_payment_error->payment_method->id }}'
            }).then(function(result) {
                if (result.error) {
                    removeMaskLoading();

                    // Show error to your customer
                    new Dialog('alert', {
                        message: result.error.message,
                        ok: function() {
                            window.location = '{{ Billing::getReturnUrl() }}';
                        }
                    });
                } else {
                    if (result.paymentIntent.status === 'succeeded') {
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
                
        </script>
    </body>
</html>