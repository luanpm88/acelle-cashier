<html lang="en">
    <head>
        <title>{{ trans('cashier::messages.stripe.checkout.page_title') }}</title>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
        <script type="text/javascript" src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
    </head>
    
    <body>
        <script src="https://js.stripe.com/v3/"></script>
        <script>
            var stripe = Stripe('{{ $service->publishableKey }}');
            
            stripe.redirectToCheckout({
                // Make the id field from the Checkout Session creation API response
                // available to this file, so you can provide it as parameter here
                // instead of the placeholder.
                sessionId: '{{ $session->id }}',
            }).then(function (result) {
                // If `redirectToCheckout` fails due to a browser or network
                // error, display the localized error message to your customer
                // using `result.error.message`.
            });
        </script>            
    </body>
</html>