<html lang="en">
    <head>
        <title>{{ trans('cashier::messages.razorpay') }}</title>
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
                        {{ trans('cashier::messages.razorpay') }}
                    </strong>
                </label>
                <img class="rounded" width="100%" src="{{ \Acelle\Cashier\Cashier::public_url('/vendor/acelle-cashier/image/razorpay.png') }}" />
            </div>
            <div class="col-md-4 mt-40 pd-60">                
                <h2 class="mb-40">{{ $invoice->title }}</h2>
                <p>{!! trans('cashier::messages.razorpay.checkout.intro', [
                    'price' => $invoice->formattedTotal(),
                ]) !!}</p>

                <a href="javascript:;" class="btn btn-secondary" onclick="$('.razorpay-payment-button').click()">
                    {{ trans('cashier::messages.razorpay.pay_with_razorpay') }}
                </a>
                
                <div class="hide" style="display:none">
                    <form action="{{ \Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\RazorpayController@checkout', [
                    '_token' => csrf_token(),
                    'invoice_uid' => $invoice->uid,
                ]) }}" method="POST">

                        <script
                            src="https://checkout.razorpay.com/v1/checkout.js"
                            data-key="{{ $service->keyId }}" // Enter the Test API Key ID generated from Dashboard → Settings → API Keys
                            data-amount="{{ $service->convertPrice($invoice->total(), $invoice->getCurrencyCode()) }}" // Amount is in currency subunits. Hence, 29935 refers to 29935 paise or ₹299.35.
                            data-currency="{{ $invoice->getCurrencyCode() }}" //You can accept international payments by changing the currency code. Contact our Support Team to enable International for your account
                            data-order_id="{{ $order["id"] }}" //Replace with the order_id generated by you in the backend.
                            data-buttontext="{{ trans('cashier::messages.razorpay.pay_with_razorpay') }}"
                            data-name="{{ $invoice->title }}"
                            data-prefill.email="{{ $invoice->billing_email }}"
                            data-theme.color="#F37254"
                            data-customer_id="{{ $customer["id"] }}"
                            data-save="1"
                        ></script>
                        <input type="hidden" custom="Hidden Element" name="hidden">
                    </form>
                </div>

                <div class="my-4">
                    <hr>
                    <form id="cancelForm" method="POST" action="{{ action('SubscriptionController@cancelInvoice', [
                                'invoice_uid' => $invoice->uid,
                    ]) }}">
                        {{ csrf_field() }}
                        <a href="javascript:;" onclick="$('#cancelForm').submit()">
                            {{ trans('messages.subscription.cancel_now_change_other_plan') }}
                        </a>
                    </form>
                    
                </div>

            </div>
            <div class="col-md-2"></div>
        </div>
        <br />
        <br />
        <br />

        
    </body>
</html>