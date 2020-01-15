<html lang="en">
    <head>
        <title>{{ trans('cashier::messages.direct.checkout.page_title') }}</title>
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
                display: flex;
            }
            .dotted-list>li>div {
                padding: 0;
                display: block;
                margin-bottom: 1px;
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
            label {
                display: inline-block;
                width: 170px;
                font-weight: 600;
            }
        </style>
    </head>
    
    <body>
        <div class="row mt-40">
            <div class="col-md-2"></div>
            <div class="col-md-4 mt-40 pd-60">
                <label class="text-semibold text-muted mb-20 mt-0">
                    <strong>
                        {{ trans('cashier::messages.direct.pending_transaction') }}
                    </strong>
                </label>
                <img width="100%" src="{{ url('/vendor/acelle-cashier/image/direct.png') }}" />
            </div>
            <div class="col-md-4 mt-40 pd-60">
                <label>{{ $subscription->plan->getBillableName() }}</label>  
                
        

                @if (!$transaction['payment_claimed'])
                    <h2 class="mb-40">{{ $subscription->plan->getBillableFormattedPrice() }}</h2>

                    <p>{!! trans('cashier::messages.direct.pending.intro', [
                        'plan' => $subscription->plan->getBillableName(),
                        'price' => $subscription->plan->getBillableFormattedPrice(),
                    ]) !!}</p>  
                    <ul class="dotted-list topborder section mb-4">
                        <li>
                            <div class="unit size1of2">
                                {{ trans('cashier::messages.direct.description') }}
                            </div>
                            <div class="lastUnit size1of2">
                                <mc:flag>{!! $data['description'] !!}</mc:flag>
                            </div>
                        </li>
                        <li>
                            <div class="unit size1of2">
                                {{ trans('cashier::messages.direct.plan') }}
                            </div>
                            <div class="lastUnit size1of2">
                                <mc:flag>{{ $subscription->plan->getBillableName() }}</mc:flag>
                            </div>
                        </li>
                        <li>
                            <div class="unit size1of2">
                                {{ trans('cashier::messages.direct.amount') }}
                            </div>
                            <div class="lastUnit size1of2">
                                <mc:flag>{{ $data['amount'] }}</mc:flag>
                            </div>
                        </li>
                        <li>
                            <div class="unit size1of2">
                                {{ trans('cashier::messages.direct.next_period_day') }}
                            </div>
                            <div class="lastUnit size1of2">
                                <mc:flag>{{ Carbon\Carbon::createFromTimestamp($data['periodEndsAt']) }}</mc:flag>
                            </div>
                        </li>
                    </ul>
                    <div class="alert alert-info">
                    {!! $gatewayService->getPaymentInstruction() !!}
                    </div>
                    <hr>
                    <form method="POST" action="{{ action('\Acelle\Cashier\Controllers\DirectController@pendingClaim', ['subscription_id' => $subscription->uid]) }}">
                        {{ csrf_field() }}
                        
                        <button
                            class="btn btn-primary mr-10 mr-2"
                        >{{ trans('cashier::messages.direct.claim_payment') }}</button>
                            
                        @if (!$subscription->isPending())
                            <a
                            href="{{ $return_url }}"
                                class="btn btn-secondary mr-10"
                            >{{ trans('cashier::messages.direct.return_back') }}</a>
                        @else
                            <form class="mt-5" method="POST" action="{{ action('\Acelle\Cashier\Controllers\DirectController@cancelNow', ['subscription_id' => $subscription->uid]) }}">
                                {{ csrf_field() }}
                                
                                <a style="display: block;" href="javascript:;" onclick="$(this).closest('form').submit()"
                                    class="text-muted mt-5" style="font-size: 12px; text-decoration: underline"
                                >{{ trans('cashier::messages.stripe.cancel_new_subscription') }}</a>
                            </form>
                        @endif
                    </form>
                @else
                    <h2 class="mb-40">{!! trans('cashier::messages.direct.pending.claimed.please_wait') !!}</h2>

                    {!! $gatewayService->getPaymentConfirmationMessage() !!}

                    <hr>
                        
                    <ul class="dotted-list">
                        <li>
                            <label>{{ trans('cashier::messages.direct.description') }}</label>
                            <span>{!! $data['description'] !!}</span>
                        </li>
                        <li>
                            <label>{{ trans('cashier::messages.direct.plan') }}</label>
                            <span>{{ $subscription->plan->getBillableName() }}</span>
                        </li>
                        <li>
                            <label>{{ trans('cashier::messages.direct.amount') }}</label>
                            <span><strong>{{ $data['amount'] }}</strong></span>
                        </li>
                        <li>
                            <label>{{ trans('cashier::messages.direct.next_period_day') }}</label>
                            <span>{{ Carbon\Carbon::createFromTimestamp($data['periodEndsAt']) }}</span>
                        </li>
                    </ul>                
                    
                    <form method="POST" action="{{ action('\Acelle\Cashier\Controllers\DirectController@pendingUnclaim', [
                        'subscription_id' => $subscription->uid,
                        'transaction_id' => $transaction['ID'],
                    ]) }}">
                        {{ csrf_field() }}
                        
                        {{-- <button
                            class="btn btn-secondary bg-grey mr-10 mb-10"
                        >{{ trans('cashier::messages.direct.unclaim_payment') }}</button> --}}

                    </form>

                    @if (!$subscription->isPending())
                        <a
                        href="{{ $return_url }}"
                            class="btn btn-secondary mr-10"
                        >{{ trans('cashier::messages.direct.return_back') }}</a>
                    @else
                        <form class="mt-5" method="POST" action="{{ action('\Acelle\Cashier\Controllers\DirectController@cancelNow', ['subscription_id' => $subscription->uid]) }}">
                            {{ csrf_field() }}
                            
                            <a style="display: block;" href="javascript:;" onclick="$(this).closest('form').submit()"
                                class="text-muted mt-5" style="font-size: 12px; text-decoration: underline"
                            >{{ trans('cashier::messages.stripe.cancel_new_subscription') }}</a>
                        </form>
                    @endif
                @endif
            </div>
            <div class="col-md-2"></div>
        </div>
        <br />
        <br />
        <br />
    </body>
</html>