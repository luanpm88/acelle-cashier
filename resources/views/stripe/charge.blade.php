<html lang="en">
    <head>
        <title>{{ trans('cashier::messages.stripe.checkout.page_title') }}</title>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
        <script type="text/javascript" src="https://code.jquery.com/jquery-3.4.1.min.js"></script>            
        <link rel="stylesheet" href="{{ url('/vendor/acelle-cashier/css/main.css') }}">
    </head>
    
    <body>
    
        <div class="row">
            <div class="col-md-2"></div>
            <div class="col-md-8 text-center" style="margin-top: 30vh">
                <div class="mb-40">
                    <img src="{{ url('images/loading.gif') }}" />
                </div>
                <h1 class="text-semibold mb-10">{!! trans('cashier::messages.stripe.checkout.processing_payment') !!}</h1>
        
                <div class="sub-section">
                    <div class="row">
                        <div class="col-md-12">
                            
                        
                            <p class="text-muted">{!! trans('cashier::messages.stripe.checkout.processing_payment.intro') !!}</p>
                            
                            <form id="pay_now" method="POST" action="{{ action('\Acelle\Cashier\Controllers\StripeController@charge', [
                                'subscription_id' => $subscription->uid,
                            ]) }}">
                                {{ csrf_field() }}
                            </form>
        
                            <script>
                                setTimeout(function() {
                                    $('#pay_now').submit();
                                }, 2000);
                            </script>
                        </div>
                    </div>
        
                </div>
            </div>
        </div>

    </body>
</html>
