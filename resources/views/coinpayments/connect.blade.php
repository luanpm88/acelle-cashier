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

    @include("account._menu", ['tab' => 'billing'])

    <div class="row">
        <div class="col-md-6">
            <h2 class="mb-4">{!! trans('cashier::messages.coinpayments.connected.thanks') !!}</h2>  

            <p>{!! trans('cashier::messages.coinpayments.connected.intro') !!}</p> 

            <a
                href="{{ $return_url }}"
                class="btn btn-mc_primary mt-4"
            >{{ trans('messages.ok') }}</a>
        </div>
        <div class="col-md-2"></div>
    </div>
@endsection