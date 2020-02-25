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
            <h2>{!! trans('cashier::messages.paypal_subscription.transaction.pending.title') !!}</h2>  

            <p>{!! trans('cashier::messages.paypal_subscription.transaction.pending.intro', [
                'price' => $transaction->amount,
                'message' => $transaction->title,
            ]) !!}</p>  

            <ul class="dotted-list topborder section mb-4">
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
                        {{ trans('cashier::messages.paypal_subscription.transaction.period_ends_at') }}
                    </div>
                    <div class="lastUnit size1of2 text-bold">
                        <mc:flag>{!! $transaction->current_period_ends_at !!}</mc:flag>
                    </div>
                </li>                
            </ul>

            <br>
            <a href="{{ $return_url }}" onclick=""
                class="mt-4 btn btn-primary btn-mc_primary"
            >{{ trans('cashier::messages.paypal_subscription.return_back') }}</a>
        </div>
        <div class="col-md-2"></div>
    </div>
@endsection