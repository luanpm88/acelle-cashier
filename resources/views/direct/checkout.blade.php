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
                        {{ trans('cashier::messages.direct.title') }}
                    </strong>
                </label>
                <img width="100%" src="{{ url('/vendor/acelle-cashier/image/direct.png') }}" />
            </div>
            <div class="col-md-4 mt-40 pd-60">
                <label>{{ $subscription->plan->getBillableName() }}</label>                  
                    
                @if (!$service->isClaimed($transaction))
                    
                    <h2 class="mb-40">{{ $subscription->plan->getBillableFormattedPrice() }}</h2>

                    <p>{!! trans('cashier::messages.direct.checkout.intro', [
                        'plan' => $subscription->plan->getBillableName(),
                        'price' => $subscription->plan->getBillableFormattedPrice(),
                    ]) !!}</p>
                    <div class="alert alert-info">
                    {!! $service->getPaymentInstruction() !!}
                    </div>
                    <hr>
                    <form method="POST" action="{{ action('\Acelle\Cashier\Controllers\DirectController@claim', ['subscription_id' => $subscription->uid]) }}">
                        {{ csrf_field() }}
                        <button
                            class="btn btn-secondary mr-10"
                        >{{ trans('cashier::messages.direct.claim_payment') }}</button>
                    </form>
                @else
                    <h2 class="mb-40">{!! trans('cashier::messages.direct.pending.claimed.please_wait') !!}</h2>

                    {!! $service->getPaymentConfirmationMessage() !!}
                @endif
                
                <form class="mt-5" method="POST" action="{{ action('\Acelle\Cashier\Controllers\DirectController@cancelNow', ['subscription_id' => $subscription->uid]) }}">
                    {{ csrf_field() }}
                    
                    <a href="javascript:;" onclick="$(this).closest('form').submit()"
                        class="text-muted" style="font-size: 12px; text-decoration: underline"
                    >{{ trans('cashier::messages.direct.cancel_new_subscription') }}</a>
                </form>
            </div>
            <div class="col-md-2"></div>
        </div>
        <br />
        <br />
        <br />
    </body>
</html>