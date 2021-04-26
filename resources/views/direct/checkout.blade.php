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

    @if ($invoice->isClaimed())
        <div class="row">
            <div class="col-md-6">
                <h2>{!! trans('cashier::messages.direct.invoice.claimed') !!}</h2>  

                <p>{!! trans('cashier::messages.direct.invoice.claimed.intro', [
                    'amount' => format_price($invoice->total(), $invoice->currency->format),
                ]) !!}</p>
            </div>
            <div class="col-md-2"></div>
            <div class="col-md-4">
                @include('invoices.bill', [
                    'bill' => $invoice->getBillingInfo(),
                ])
            </div>
        </div>
    @else
        <div class="row">
            <div class="col-md-6">
                <h2>{!! trans('cashier::messages.direct.invoice.pay_invoice') !!}</h2>  

                <p>{!! trans('cashier::messages.direct.invoice.pay_invoice.intro', [
                    'amount' => format_price($invoice->total(), $invoice->currency->format),
                ]) !!}</p>

                <div class="alert alert-info bg-grey-light">
                    {!! $service->getPaymentInstruction() !!}
                </div>
                <hr>
                    
                <div class="d-flex align-items-center">
                    <form method="POST"
                        action="{{ \Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\DirectController@claim', [
                            'invoice_uid' => $invoice->uid
                        ]) }}"
                    >
                        {{ csrf_field() }}
                        <button
                            class="btn btn-primary mr-10 mr-2"
                        >{{ trans('cashier::messages.direct.claim_payment') }}</button>
                    </form>
                </div>
                
            </div>
            <div class="col-md-2"></div>
            <div class="col-md-4">
                @include('invoices.bill', [
                    'bill' => $invoice->getBillingInfo(),
                ])
            </div>
        </div>
    @endif
@endsection