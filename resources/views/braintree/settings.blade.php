@extends('layouts.backend')

@section('title', trans('cashier::messages.braintree'))

@section('page_script')
	<script type="text/javascript" src="{{ URL::asset('assets/js/plugins/forms/styling/uniform.min.js') }}"></script>

    <script type="text/javascript" src="{{ URL::asset('js/validate.js') }}"></script>
	<script type="text/javascript" src="{{ URL::asset('js/tinymce/tinymce.min.js') }}"></script>
        
    <script type="text/javascript" src="{{ URL::asset('js/editor.js') }}"></script>
@endsection

@section('page_header')

    <div class="page-title">
        <ul class="breadcrumb breadcrumb-caret position-right">
            <li><a href="{{ action("HomeController@index") }}">{{ trans('messages.home') }}</a></li>
            <li><a href="{{ action("Admin\PaymentController@index") }}">{{ trans('messages.payment_gateways') }}</a></li>
            <li class="active">{{ trans('messages.update') }}</li>
        </ul>
        <h1>
            <span class="text-semibold"><i class="icon-credit-card2"></i> {{ trans('cashier::messages.braintree') }}</span>
        </h1>
    </div>

@endsection

@section('content')
		<div class="row">
			<div class="col-md-6">
				<p>
					{!! trans('cashier::messages.braintree.intro') !!}
				</p>
			</div>
		</div>
			
		<h3>{{ trans('cashier::messages.payment.options') }}</h3>

        <form enctype="multipart/form-data" action="{{ $gateway->getSettingsUrl() }}" method="POST" class="form-validate-jquery">
            {{ csrf_field() }}
            <div class="row">
                <div class="col-md-6">
                    @include('helpers.form_control', [
                        'type' => 'select',
                        'name' => 'environment',
                        'value' => $gateway->getEnvironment(),
                        'label' => trans('cashier::messages.braintree.environment'),
                        'help_class' => 'payment',
                        'options' => [['text' => 'Sandbox', 'value' => 'sandbox'],['text' => 'Production', 'value' => 'production']],
                        'rules' => ['environment' => 'required'],
                    ])
                    
                    @include('helpers.form_control', [
                        'type' => 'text',
                        'name' => 'merchant_id',
                        'value' => $gateway->getMerchantId(),
                        'label' => trans('cashier::messages.braintree.merchant_id'),
                        'help_class' => 'payment',
                        'rules' => ['merchant_id' => 'required'],
                    ])
                    
                    @include('helpers.form_control', [
                        'type' => 'text',
                        'name' => 'public_key',
                        'value' => $gateway->getPublicKey(),
                        'label' => trans('cashier::messages.braintree.public_key'),
                        'help_class' => 'payment',
                        'rules' => ['public_key' => 'required'],
                    ])

                    @include('helpers.form_control', [
                        'type' => 'text',
                        'name' => 'private_key',
                        'value' => $gateway->getPrivateKey(),
                        'label' => trans('cashier::messages.braintree.private_key'),
                        'help_class' => 'payment',
                        'rules' => ['private_key' => 'required'],
                    ])
                </div>
            </div>


            <hr>
            <div class="text-left">
                @if ($gateway->isActive())
                    @if (!\Acelle\Library\Facades\Billing::isGatewayEnabled($gateway))
                        <input type="submit" name="enable_gateway" class="btn btn-mc_primary mr-5" value="{{ trans('cashier::messages.save_and_enable') }}" />
                        <button class="btn btn-mc_default mr-5">{{ trans('messages.save') }}</button>
                    @else
                        <button class="btn btn-mc_primary mr-5">{{ trans('messages.save') }}</button>
                    @endif
                @else
                    <input type="submit" name="enable_gateway" class="btn btn-mc_primary mr-5" value="{{ trans('cashier::messages.connect') }}" />
                @endif
                <a class="btn btn-mc_default" href="{{ action('Admin\PaymentController@index') }}">{{ trans('messages.cancel') }}</a>
            </div>

        </form>

@endsection