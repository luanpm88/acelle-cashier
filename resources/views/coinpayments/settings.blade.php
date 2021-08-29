@extends('layouts.core.backend')

@section('title', trans('cashier::messages.coinpayments'))

@section('page_header')

    <div class="page-title">
        <ul class="breadcrumb breadcrumb-caret position-right">
            <li class="breadcrumb-item"><a href="{{ action("HomeController@index") }}">{{ trans('messages.home') }}</a></li>
            <li class="breadcrumb-item"><a href="{{ action("Admin\PaymentController@index") }}">{{ trans('messages.payment_gateways') }}</a></li>
            <li class="breadcrumb-item active">{{ trans('messages.update') }}</li>
        </ul>
        <h1>
            <span class="text-semibold"><i class="icon-credit-card2"></i> {{ trans('cashier::messages.coinpayments') }}</span>
        </h1>
    </div>

@endsection

@section('content')
		<div class="row">
			<div class="col-md-6">
				<p>
					{!! trans('cashier::messages.coinpayments.intro') !!}
				</p>
			</div>
		</div>
			
		<h3>{{ trans('cashier::messages.payment.options') }}</h3>

        <form enctype="multipart/form-data" action="{{ $gateway->getSettingsUrl() }}" method="POST" class="form-validate-jquery">
            {{ csrf_field() }}
            <div class="row">
                <div class="col-md-6">
                    @include('helpers.form_control', [
                        'type' => 'text',
                        'name' => 'merchant_id',
                        'value' => $gateway->merchantId,
                        'label' => trans('cashier::messages.coinpayments.merchant_id'),
                        'help_class' => 'payment',
                        'rules' => ['merchant_id' => 'required'],
                    ])
                    
                    @include('helpers.form_control', [
                        'type' => 'text',
                        'name' => 'public_key',
                        'value' => $gateway->publicKey,
                        'label' => trans('cashier::messages.coinpayments.public_key'),
                        'help_class' => 'payment',
                        'rules' => ['public_key' => 'required'],
                    ])
                    
                    @include('helpers.form_control', [
                        'type' => 'text',
                        'name' => 'private_key',
                        'value' => $gateway->privateKey,
                        'label' => trans('cashier::messages.coinpayments.private_key'),
                        'help_class' => 'payment',
                        'rules' => ['private_key' => 'required'],
                    ])
                    
                    @include('helpers.form_control', [
                        'type' => 'text',
                        'name' => 'ipn_secret',
                        'value' => $gateway->ipnSecret,
                        'label' => trans('cashier::messages.coinpayments.ipn_secret'),
                        'help_class' => 'payment',
                        'rules' => ['ipn_secret' => 'required'],
                    ])
                    
                    @include('helpers.form_control', [
                        'type' => 'text',
                        'name' => 'receive_currency',
                        'value' => $gateway->receiveCurrency,
                        'label' => trans('cashier::messages.coinpayments.receive_currency'),
                        'help_class' => 'payment',
                        'rules' => ['receive_currency' => 'required'],
                    ])
                </div>
            </div>


            <hr>
            <div class="text-left">
                @if ($gateway->isActive())
                    @if (!\Acelle\Library\Facades\Billing::isGatewayEnabled($gateway))
                        <input type="submit" name="enable_gateway" class="btn btn-primary me-1" value="{{ trans('cashier::messages.save_and_enable') }}" />
                        <button class="btn btn-default me-1">{{ trans('messages.save') }}</button>
                    @else
                        <button class="btn btn-primary me-1">{{ trans('messages.save') }}</button>
                    @endif
                @else
                    <input type="submit" name="enable_gateway" class="btn btn-primary me-1" value="{{ trans('cashier::messages.connect') }}" />
                @endif
                <a class="btn btn-default" href="{{ action('Admin\PaymentController@index') }}">{{ trans('messages.cancel') }}</a>
            </div>

        </form>

@endsection