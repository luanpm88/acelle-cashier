<html lang="en">
    <head>
        <title>{{ trans('cashier::messages.coinpayments.checkout.page_title') }}</title>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
        <script type="text/javascript" src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
            
        <style>
            body {
                background: #f9f9f9;
            }
            body:before {
                height: 100%;
                width: 50%;
                position: fixed;
                content: " ";
                top: 0;
                right: 0;
                background: #fff;
                -webkit-animation: enter-background-shadow .6s;
                animation: enter-background-shadow .6s;
                -webkit-animation-fill-mode: both;
                animation-fill-mode: both;
                -webkit-transform-origin: right;
                -ms-transform-origin: right;
                transform-origin: right;
            }            
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
            .pd-60 {
                padding: 60px;
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
            .logo-wrapper {
                padding: 30px 20px 50px 20px;
                background: #eee;
                border-radius: 20px;
            }
        </style>
    </head>
    
    <body>
        <div class="row mt-40">
            <div class="col-md-2"></div>
            <div class="col-md-4 mt-40 pl-20 pd-60">
                <label class="text-semibold text-muted mb-20 mt-0">
                    <strong>
                        {{ trans('cashier::messages.coinpayments.checkout.page_title') }}
                    </strong>
                </label>
                <div class="logo-wrapper">
                    <img width="100%" src="{{ url('/vendor/acelle-cashier/image/coinpayments.png') }}" />
                </div>
            </div>
            <div class="col-md-4 mt-40 pd-60">
                <label>{{ $subscription->plan->getBillableName() }}</label>  
                <h2 class="mb-40">{{ $subscription->plan->getBillableFormattedPrice() }}</h2>
                
                {{-- @if (!$transaction) --}}
                    <p>{!! trans('cashier::messages.coinpayments.checkout.intro', [
                        'plan' => $subscription->plan->getBillableName(),
                        'price' => $subscription->plan->getBillableFormattedPrice(),
                    ]) !!}</p>

                    <ul class="dotted-list topborder section mb-4">
                        <li>
                            <div class="unit size1of2">
                                {{ trans('cashier::messages.coinpayments.plan') }}
                            </div>
                            <div class="lastUnit size1of2">
                                <mc:flag>{{ $subscription->plan->getBillableName() }}</mc:flag>
                            </div>
                        </li>
                        <li>
                            <div class="unit size1of2">
                                {{ trans('cashier::messages.coinpayments.next_period_day') }}
                            </div>
                            <div class="lastUnit size1of2">
                                <mc:flag>{{ $subscription->current_period_ends_at }}</mc:flag>
                            </div>
                        </li>
                        <li>
                            <div class="unit size1of2">
                                {{ trans('cashier::messages.coinpayments.amount') }}
                            </div>
                            <div class="lastUnit size1of2">
                                <mc:flag>{{ $subscription->plan->getBillableFormattedPrice() }}</mc:flag>
                            </div>
                        </li>
                    </ul>
                    
                    <a href="{{ action('\Acelle\Cashier\Controllers\CoinpaymentsController@charge', [
                        'subscription_id' => $subscription->uid,
                    ]) }}" class="btn btn-secondary">{{ trans('cashier::messages.coinpayments.pay_now') }}</a>
                    
                    <form class="mt-5" method="POST" action="{{ action('\Acelle\Cashier\Controllers\CoinpaymentsController@cancelNow', ['subscription_id' => $subscription->uid]) }}">
                        {{ csrf_field() }}
                        
                        <a href="javascript:;" onclick="$(this).closest('form').submit()"
                            class="text-muted" style="font-size: 12px; text-decoration: underline"
                        >{{ trans('cashier::messages.stripe.cancel_new_subscription') }}</a>
                    </form>
                {{-- @else
                    @if ($subscription->isActive())
                        <p>{!! trans('cashier::messages.coinpayments.checkout.complete', [
                            'plan' => $subscription->plan->getBillableName(),
                            'price' => $subscription->plan->getBillableFormattedPrice(),
                        ]) !!}</p>
                    @else                        
                        <p>{!! trans('cashier::messages.coinpayments.checkout.pay_intro', [
                            'plan' => $subscription->plan->getBillableName(),
                            'price' => $subscription->plan->getBillableFormattedPrice(),
                        ]) !!}</p>
                        <hr>
                            {!! trans('cashier::messages.coinpayments.checkout_status', [
                                'status' => $gatewayService->getTransactionStatus($transaction['status']),
                                'message' => $transaction['status_text'],
                            ]) !!}
                        <hr>
                        <p>{!! trans('cashier::messages.coinpayments.checkout_link', [
                            'url' => $subscription->getMetadata()['checkout_url'],
                        ]) !!}</p>
                        <hr>
                        <p>{!! trans('cashier::messages.coinpayments.status_link', [
                            'url' => $subscription->getMetadata()['status_url'],
                        ]) !!}</p>
                    @endif
                    
                    @if ($subscription->isEnded() || $subscription->isActive())
                        <hr>
                        <a class="btn btn-secondary" href="{{ $return_url }}">{{ trans('cashier::messages.coinpayments.return_back') }}</a>
                    @endif
                @endif --}}
            </div>
            <div class="col-md-2"></div>
        </div>
        <br />
        <br />
        <br />
    </body>
</html>