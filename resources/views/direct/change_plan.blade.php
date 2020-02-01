<html lang="en">
    <head>
        <title>{{ trans('cashier::messages.direct.change_plan') }}</title>
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
                        {{ trans('cashier::messages.direct.change_plan') }}
                    </strong>
                </label>
                <img width="100%" src="{{ url('/vendor/acelle-cashier/image/direct.png') }}" />
            </div>
            <div class="col-md-4 mt-40 pd-60">
                <label>{{ trans('cashier::messages.direct.new_plan') }}</label>  
                <h2 class="mb-40">{{ $newPlan->getBillableName() }}</h2>

                <p>{!! trans('cashier::messages.direct.change_plan.intro', [
                    'plan' => $subscription->plan->getBillableName(),
                    'new_plan' => $newPlan->getBillableName(),
                ]) !!}</p>   
                
                <ul class="dotted-list topborder section mb-4">
                    <li>
                        <div class="unit size1of2">
                            {{ trans('cashier::messages.direct.new_plan') }}
                        </div>
                        <div class="lastUnit size1of2">
                            <mc:flag>{{ $newPlan->getBillableName() }}</mc:flag>
                        </div>
                    </li>
                    <li>
                        <div class="unit size1of2">
                            {{ trans('cashier::messages.direct.next_period_day') }}
                        </div>
                        <div class="lastUnit size1of2">
                            <mc:flag>{{ $nextPeriodDay }}</mc:flag>
                        </div>
                    </li>
                    <li>
                        <div class="unit size1of2">
                            {{ trans('cashier::messages.direct.amount') }}
                        </div>
                        <div class="lastUnit size1of2">
                            <mc:flag>{{ $amount }} ({{ $newPlan->getBillableCurrency() }})</mc:flag>
                        </div>
                    </li>
                </ul>
                
                <form method="POST" action="{{ action('\Acelle\Cashier\Controllers\DirectController@changePlan', ['subscription_id' => $subscription->uid]) }}">
                    {{ csrf_field() }}
                    <input type='hidden' name='plan_id' value='{{ $newPlan->getBillableId() }}' />
                    
                    <button
                        class="btn btn-primary mr-10 mr-2"
                    >{{ trans('cashier::messages.direct.renew_proceed') }}</button>
                        
                    <a
                    href="{{ $return_url }}"
                        class="btn btn-secondary mr-10"
                    >{{ trans('cashier::messages.direct.return_back') }}</a>
                </form>
                    
            </div>
            <div class="col-md-2"></div>
        </div>
        <br />
        <br />
        <br />
    </body>
</html>