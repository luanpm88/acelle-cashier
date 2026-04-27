@extends('cashier::layouts.checkout')

@section('content')
    <h2>{!! trans('cashier::messages.pay_invoice') !!}</h2>

    <div class="py-3">
        <p class="text-muted">
            {{ trans('cashier::messages.offline.amount_due') }}:
            <strong>{{ number_format($intent->amount, 2) }} {{ $intent->currency }}</strong>
        </p>
    </div>

    <p>{{ trans('cashier::messages.offline.payment_instructions') }}</p>
    <div class="p-4 bg-grey-light" style="min-height: 150px;">
        {!! $paymentInstruction !!}
    </div>

    <hr>

    <div class="d-flex align-items-center">
        <form method="POST"
              action="{{ action('\App\Cashier\Controllers\OfflineController@claim', ['intent_uid' => $intent->uid]) }}">
            {{ csrf_field() }}
            <input type="hidden" name="return_url" value="{{ $returnUrl }}">
            <button class="btn btn-dark me-3">
                {{ trans('cashier::messages.offline.claim_payment') }}
            </button>
        </form>

        <a class="btn btn-link" href="{{ $returnUrl }}">
            {{ trans('cashier::messages.go_back') }}
        </a>
    </div>
@endsection
