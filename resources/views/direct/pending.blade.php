<html lang="en">
    <head>
        <title>{{ trans('cashier::messages.direct.checkout.page_title') }}</title>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
        <script type="text/javascript" src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
        <link rel="stylesheet" href="{{ url('/vendor/acelle-cashier/css/main.css') }}">
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
                <label>{{ $transaction->title }}</label>  
                
        

                @if (!$service->isClaimed($transaction))
                    <h2 class="mb-40">{{ $transaction->amount }}</h2>

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
                                <mc:flag>{!! $transaction->title !!}</mc:flag>
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
                                <mc:flag>{{ $transaction->amount }}</mc:flag>
                            </div>
                        </li>
                        <li>
                            <div class="unit size1of2">
                                {{ trans('cashier::messages.direct.next_period_day') }}
                            </div>
                            <div class="lastUnit size1of2">
                                <mc:flag>{{ $transaction->ends_at }}</mc:flag>
                            </div>
                        </li>
                    </ul>
                    <div class="alert alert-info">
                    {!! $service->getPaymentInstruction() !!}
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

                    {!! $service->getPaymentConfirmationMessage() !!}
                        
                    <ul class="dotted-list topborder section mb-4">
                        <li>
                            <div class="unit size1of2">
                                {{ trans('cashier::messages.direct.description') }}
                            </div>
                            <div class="lastUnit size1of2">
                                <mc:flag>{!! $transaction->title !!}</mc:flag>
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
                                <mc:flag>{{ $transaction->amount }}</mc:flag>
                            </div>
                        </li>
                        <li>
                            <div class="unit size1of2">
                                {{ trans('cashier::messages.direct.next_period_day') }}
                            </div>
                            <div class="lastUnit size1of2">
                                <mc:flag>{{ $transaction->ends_at }}</mc:flag>
                            </div>
                        </li>
                    </ul>          
                    
                    <form method="POST" action="{{ action('\Acelle\Cashier\Controllers\DirectController@pendingUnclaim', [
                        'subscription_id' => $subscription->uid,
                        'transaction_id' => $transaction['ID'],
                    ]) }}">
                        {{ csrf_field() }}

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