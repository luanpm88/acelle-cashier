@extends('layouts.frontend')

@section('title', trans('messages.subscriptions'))

@section('page_script')
    <script type="text/javascript" src="{{ URL::asset('assets/js/plugins/forms/styling/uniform.min.js') }}"></script>
    <script type="text/javascript" src="{{ URL::asset('js/validate.js') }}"></script>
@endsection

@section('page_header')

    <div class="page-title">
        <ul class="breadcrumb breadcrumb-caret position-right">
            <li><a href="{{ \Acelle\Cashier\Cashier::lr_action("HomeController@index") }}">{{ trans('messages.home') }}</a></li>
            <li class="active">{{ trans('messages.subscription') }}</li>
        </ul>
    </div>

@endsection

@section('content')

    @include("account._menu", ['tab' => 'subscription'])

    <div class="row">
        <div class="col-md-8">
            <label></label>  
            <h2 class="mt-0">
                {{ $subscription->plan->getBillableName() }}
                ({{ $subscription->plan->getBillableFormattedPrice() }})
            </h2>

            <p>{!! trans('cashier::messages.coinpayments.pending.intro', [
                'plan' => $subscription->plan->getBillableName(),
            ]) !!}</p>
                
            <ul class="dotted-list topborder section mb-4">
                <li>
                    <div class="unit size1of3 font-weight-bold">
                        {{ trans('cashier::messages.coinpayments.status') }}
                    </div>
                    <div class="lastUnit size2of3">
                        <mc:flag>
                            {{ $transaction->getMetadata()['remote']['status_text'] }}
                        </mc:flag>
                    </div>
                </li>
                <li>
                    <div class="unit size1of3 font-weight-bold">
                        {{ trans('cashier::messages.coinpayments.plan') }}
                    </div>
                    <div class="lastUnit size2of3">
                        <mc:flag>{{ $subscription->plan->getBillableName() }}</mc:flag>
                    </div>
                </li>
                <li>
                    <div class="unit size1of3 font-weight-bold">
                        {{ trans('cashier::messages.coinpayments.next_period_day') }}
                    </div>
                    <div class="lastUnit size2of3">
                        <mc:flag>{{ $transaction->current_period_ends_at }}</mc:flag>
                    </div>
                </li>
                <li>
                    <div class="unit size1of3 font-weight-bold">
                        {{ trans('cashier::messages.coinpayments.amount') }}
                    </div>
                    <div class="lastUnit size2of3">
                        <mc:flag>{{ $transaction->amount }}</mc:flag>
                    </div>
                </li>
                <li>
                    <div class="unit size1of3 font-weight-bold">
                        {{ trans('cashier::messages.coinpayments.checkout_url') }}
                    </div>
                    <div class="lastUnit size2of3">
                        <mc:flag>
                            <a target="_blank" style="white-space: nowrap;
overflow: hidden;
text-overflow: ellipsis;
display: block;" href="{{ $transaction->getMetadata()['checkout_url'] }}">
                                {{ $transaction->getMetadata()['checkout_url'] }}
                            </a>
                        </mc:flag>
                    </div>
                </li>
                <li>
                    <div class="unit size1of3 font-weight-bold">
                        {{ trans('cashier::messages.coinpayments.status_url') }}
                    </div>
                    <div class="lastUnit size2of3">
                        <mc:flag>
                            <a target="_blank" style="white-space: nowrap;
overflow: hidden;
text-overflow: ellipsis;
display: block;" href="{{ $transaction->getMetadata()['status_url'] }}">
                                {{ $transaction->getMetadata()['status_url'] }}
                            </a>
                        </mc:flag>
                    </div>
                </li>
            </ul> 
        </div>
        <div class="col-md-2"></div>
    </div>

    <form class="mt-5" method="POST" action="{{ \Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\CoinpaymentsController@cancelNow', ['subscription_id' => $subscription->uid]) }}">
        {{ csrf_field() }}
        
        <a href="javascript:;" onclick="$(this).closest('form').submit()"
            class="text-muted" style="font-size: 12px; text-decoration: underline"
        >{{ trans('cashier::messages.coinpayments.cancel_subscription') }}</a>
    </form>
@endsection