<html lang="en">
    <head>
        <title>{{ trans('cashier::messages.direct.checkout.page_title') }}</title>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
        <script type="text/javascript" src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
            
        <style>
            .mb-10 {
                margin-bottom: 10px;
            }
            .mb-40 {
                margin-bottom: 40px;
            }
            .mb-20 {
                margin-bottom: 20px;
            }
            .mt-40 {
                margin-top: 40px;
            }
            
            ul.dotted-list {
                list-style: none;
                padding-left: 0;
            }
            .dotted-list > li {
                line-height: 24px;
                padding: 12px 0 11px;
                border-bottom: 1px dotted #e0e0e0;
                display: list-item;
            }
            .dotted-list>li>div {
                padding: 0;
                display: block;
            }
            .topborder>li:first-child {
                border-top: 1px dotted #e0e0e0;
            }
            .size1of2 {
                width: 50%;
                float: left;
            }
            .size1of3 {
                width: 33.3%;
                float: left;
            }
            .size2of3 {
                width: 66.6%;
                float: left;
            }
            .lastUnit, .lastGroup {
                float: none;
                width: auto;
            }
            .lastUnit, .unit {
                padding-left: 15px;
                padding-right: 15px;
            }
            .sub-section {
                margin-bottom: 60px;
            }
        </style>
    </head>
    
    <body>
        <div class="row mt-40">
            <div class="col-md-2"></div>
            <div class="col-md-3 text-center mt-40">
                <img width="100%" src="{{ url('/vendor/acelle-cashier/image/direct.png') }}" />
            </div>
            <div class="col-md-5 mt-40">
                <h1 class="text-semibold mb-20 mt-0">{{ trans('cashier::messages.direct.title') }}</h1>
        

                @if (!$transaction['payment_claimed'])
                    <p>{!! trans('cashier::messages.direct.checkout.intro', [
                        'plan' => $subscription->plan->getBillableName(),
                        'price' => $subscription->plan->getBillableFormattedPrice(),
                    ]) !!}</p>
                    <hr>
                    {!! $gatewayService->getPaymentGuide() !!}
                    <hr>
                    
                    <form method="POST" action="{{ action('\Acelle\Cashier\Controllers\DirectController@claim', ['subscription_id' => $subscription->uid]) }}">
                        {{ csrf_field() }}
                        <button
                            class="btn btn-secondary mr-10"
                        >{{ trans('cashier::messages.direct.claim_payment') }}</button>
                    </form>
                @else
                    <p>{!! trans('cashier::messages.direct.claimed.intro', [
                        'plan' => $subscription->plan->getBillableName(),
                        'price' => $subscription->plan->getBillableFormattedPrice(),
                    ]) !!}</p>
                    <hr>
                    
                    <form method="POST" action="{{ action('\Acelle\Cashier\Controllers\DirectController@unclaim', ['subscription_id' => $subscription->uid]) }}">
                        {{ csrf_field() }}
                        <button
                            class="btn btn-secondary mr-10"
                        >{{ trans('cashier::messages.direct.unclaim_payment') }}</button>
                    </form>
                @endif
            </div>
            <div class="col-md-2"></div>
        </div>
        <br />
        <br />
        <br />
    </body>
</html>