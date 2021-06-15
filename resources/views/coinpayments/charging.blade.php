<html lang="en">
    <head>
        <title>{{ trans('cashier::messages.coinpayments') }}</title>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
        <script type="text/javascript" src="https://code.jquery.com/jquery-3.4.1.min.js"></script>    
        <style>
            .mb-10 {
                margin-bottom: 10px;
            }
            .mb-40 {
                margin-bottom: 40px;
            }
        </style>        
    </head>
    
    <body>
    
        <div class="row">
            <div class="col-md-2"></div>
            <div class="col-md-8 text-center" style="margin-top: 30vh">
                <div class="mb-40">
                    <img src="{{ \Acelle\Cashier\Cashier::public_url('images/loading.gif') }}" />
                </div>
                <h1 class="text-semibold mb-10">{!! trans('cashier::messages.coinpayments.checkout.processing_payment') !!}</h1>
        
                <div class="sub-section">
                    <div class="row">
                        <div class="col-md-12">
                            
                        
                            <p class="text-muted">{!! trans('cashier::messages.coinpayments.checkout.processing_payment.intro') !!}</p>
                            
                            <form id="pay_now" method="POST" action="{{ \Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\CoinpaymentsController@checkout', [
                                'invoice_uid' => $invoice->uid,
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
