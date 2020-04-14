@extends('layouts.frontend')

@section('title', trans('messages.subscriptions'))

@section('page_script')
    <script type="text/javascript" src="{{ URL::asset('assets/js/plugins/forms/styling/uniform.min.js') }}"></script>
    <script type="text/javascript" src="{{ URL::asset('js/validate.js') }}"></script>
@endsection

@section('page_header')

    <div class="page-title">
        <ul class="breadcrumb breadcrumb-caret position-right">
            <li><a href="{{ action("HomeController@index") }}">{{ trans('messages.home') }}</a></li>
            <li class="active">{{ trans('messages.subscription') }}</li>
        </ul>
    </div>

@endsection

@section('content')

    @include("account._menu", ['tab' => 'subscription'])

    <div class="row">
        <div class="col-md-6">
            <h2>{!! trans('cashier::messages.direct.pending.claimed.title') !!}</h2>  
            <p class="mb-4">{!! trans('cashier::messages.direct.pending.claimed.please_wait', [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
            ]) !!}</p>

            <div class="alert alert-info bg-grey-light">
                {!! $service->getPaymentConfirmationMessage() !!}
            </div>
            
            <h2 class="mt-40">{!! trans('cashier::messages.direct.subscription.details') !!}</h2>  
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
            <br>
                <form class="mt-4" method="POST" action="{{ action('\Acelle\Cashier\Controllers\DirectController@cancelNow', ['subscription_id' => $subscription->uid]) }}">
                    {{ csrf_field() }}
                    
                    <a href="javascript:;" onclick="$(this).closest('form').submit()"
                        class="text-muted"
                        style="font-size: 13px; text-decoration: underline;color:#333"
                    >{{ trans('cashier::messages.direct.change_mind_cancel_subscription') }}</a>
                </form>
        </div>
        <div class="col-md-2"></div>
    </div>
@endsection