@extends('layouts.core.frontend_dark', [
    'subscriptionPage' => true,
])

@section('title', trans('messages.subscriptions'))

@section('menu_title')
    @include('subscription._title')
@endsection

@section('menu_right')
    <li class="nav-item d-flex align-items-center">
        <a href="{{ url("/logout") }}" class="nav-link d-flex align-items-center">
            <i class="navbar-icon me-1">
                <span class="material-symbols-rounded">power_settings_new</span>
            </i>{{ trans('messages.logout') }}
        </a>
    </li>
@endsection

@section('content')
    <div class="container mt-4 mb-5">
        <div class="row">
            <div class="col-md-6">
                <h2>{!! trans('cashier::messages.pay_invoice') !!}</h2>  

                <div class="alert alert-info bg-grey-light">
                    {!! $service->getPaymentInstruction() !!}
                </div>
                <hr>
                    
                <div class="d-flex align-items-center">
                    <form method="POST"
                        action="{{ \Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\OfflineController@claim', [
                            'invoice_uid' => $invoice->uid
                        ]) }}"
                    >
                        {{ csrf_field() }}
                        <button
                            class="btn btn-primary mr-10 mr-4"
                        >{{ trans('cashier::messages.offline.claim_payment') }}</button>
                    </form>

                    <form id="cancelForm" method="POST" action="{{ action('SubscriptionController@cancelInvoice', [
                                'invoice_uid' => $invoice->uid,
                    ]) }}">
                        {{ csrf_field() }}
                        <a href="{{ Billing::getReturnUrl() }}">
                            {{ trans('cashier::messages.go_back') }}
                        </a>
                    </form>
                </div>
                
            </div>
            <div class="col-md-2"></div>
            <div class="col-md-4">
                <div class="card shadow-sm rounded-3 px-2 py-2 mb-4">
                    <div class="card-body p-4">
                        @include('invoices.bill', [
                            'bill' => $invoice->getBillingInfo(),
                        ])
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection