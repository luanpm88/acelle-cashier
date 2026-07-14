@extends('cashier::layouts.checkout')

@section('content')
    <h2>{!! trans('cashier::messages.pay_invoice') !!}</h2>

    <div class="py-3">
        {{-- Offline realizes a coupon LOCALLY: show the NET (amount − coupon_amount) so the buyer
             transfers the discounted sum, matching what completeInvoice records as amount_paid. --}}
        <p class="text-muted">
            {{ trans('cashier::messages.offline.amount_due') }}:
            <strong>{{ number_format($intent->netAmountAfterDiscount(), 2) }} {{ $intent->currency }}</strong>
        </p>
        @if ($intent->isDiscounted())
            <p class="text-muted" style="font-size: 0.875em;">
                {{ trans('cashier::messages.offline.original_amount') }}: {{ number_format($intent->amount, 2) }} {{ $intent->currency }}
                &middot;
                {{ trans('cashier::messages.offline.discount') }}: &minus;{{ number_format((float) ($intent->metadata['coupon_amount'] ?? 0), 2) }} {{ $intent->currency }}
            </p>
        @endif
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
