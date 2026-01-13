@extends('cashier::layouts.checkout')

@section('content')
    <h2>{!! trans('cashier::messages.pay_invoice') !!}</h2>  

    <p>{{ trans('cashier::messages.offline.payment_instructions') }}</p>
    <div class="p-4 bg-grey-light" style="min-height: 150px;">
        {!! $paymentGateway->getGatewayData('payment_instruction') !!}
    </div>
    <hr>
        
    <div class="d-flex align-items-center">
        <form method="POST"
            action="{{ \Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\OfflineController@claim', [
                'invoice_uid' => $invoice->uid,
                'payment_gateway_id' => $paymentGateway->uid,
            ]) }}"
        >
            {{ csrf_field() }}
            <button
                class="btn btn-dark mr-10 mr-4"
            >{{ trans('cashier::messages.offline.claim_payment') }}</button>
        </form>

        <form id="cancelForm" method="POST" action="{{ action('SubscriptionController@cancelInvoice', [
                    'invoice_uid' => $invoice->uid,
        ]) }}">
            {{ csrf_field() }}
            <a class="btn btn-link" href="{{ Billing::getReturnUrl() }}">
                {{ trans('cashier::messages.go_back') }}
            </a>
        </form>
    </div>
@endsection