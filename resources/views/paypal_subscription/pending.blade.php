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
            <h2>{!! trans('cashier::messages.paypal_subscription.pending.title') !!}</h2>  

            <p>{!! trans('cashier::messages.paypal_subscription.pending.intro', [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
            ]) !!}</p>  

            <ul class="dotted-list topborder section mb-4">
                <li>
                    <div class="unit size1of2">
                        {{ trans('cashier::messages.paypal_subscription.paypal_status') }}
                    </div>
                    <div class="lastUnit size1of2 text-bold">
                        <mc:flag>
                            <span class="badge badge-info bg-paypal-status-{!! $paypalSubscription['status'] !!}">
                                {!! $paypalSubscription['status'] !!}
                            </span>
                        </mc:flag>
                    </div>
                </li>
                <li>
                    <div class="unit size1of2">
                        {{ trans('cashier::messages.paypal_subscription.status.message') }}
                    </div>
                    <div class="lastUnit size1of2 text-bold">
                        <mc:flag>{!! trans('cashier::messages.paypal_subscription.status.message.' . $paypalSubscription['status']) !!}</mc:flag>
                    </div>
                </li>
                <li>
                    <div class="unit size1of2">
                        {{ trans('cashier::messages.paypal_subscription.start_time') }}
                    </div>
                    <div class="lastUnit size1of2 text-bold">
                        <mc:flag>{!! Carbon\Carbon::parse($paypalSubscription['start_time']) !!}</mc:flag>
                    </div>
                </li>
                <li>
                    <div class="unit size1of2">
                        {{ trans('cashier::messages.paypal_subscription.description') }}
                    </div>
                    <div class="lastUnit size1of2">
                        <mc:flag>{!! $transaction->title !!}</mc:flag>
                    </div>
                </li>
                <li>
                    <div class="unit size1of2">
                        {{ trans('cashier::messages.paypal_subscription.plan') }}
                    </div>
                    <div class="lastUnit size1of2">
                        <mc:flag>{{ $subscription->plan->getBillableName() }}</mc:flag>
                    </div>
                </li>
                <li>
                    <div class="unit size1of2">
                        {{ trans('cashier::messages.paypal_subscription.amount') }}
                    </div>
                    <div class="lastUnit size1of2">
                        <mc:flag>{{ $transaction->amount }}</mc:flag>
                    </div>
                </li>
            </ul>

            <br>
            <form class="mt-4" method="POST" action="{{ action('\Acelle\Cashier\Controllers\DirectController@cancelNow', ['subscription_id' => $subscription->uid]) }}">
                {{ csrf_field() }}
                
                <a href="javascript:;" onclick="$(this).closest('form').submit()"
                    class="text-muted"
                    style="font-size: 13px; text-decoration: underline;color:#333"
                >{{ trans('cashier::messages.paypal_subscription.change_mind_cancel_subscription') }}</a>
            </form>
        </div>
        <div class="col-md-2"></div>
    </div>
@endsection