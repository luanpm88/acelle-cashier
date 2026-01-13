<html lang="en">
    <head>
        <title>{{ trans('cashier::messages.stripe.checkout.page_title') }}</title>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
        <script type="text/javascript" src="https://code.jquery.com/jquery-3.4.1.min.js"></script>            
        <link rel="stylesheet" href="{{ \Acelle\Cashier\Cashier::public_url('/vendor/acelle-cashier/css/main.css') }}">

        <meta name="viewport" content="width=device-width, initial-scale=1" />

        @include('layouts.core._includes')

        @include('layouts.core._script_vars')

        <script src="https://js.stripe.com/v3/"></script>
    </head>
    
    <body class="py-4">
        <div class="px-5 py-3" style="max-width:1200px;margin:auto;">
            <div class="row">
                <!-- Left side: Invoice details -->
                <div class="col-md-6">
                    <div class="bg-light shadow-sm rounded px-4 py-4">
                        <div class="mb-5">
                            
                            <span>
                                <span class="badge badge-light bg-dark">{{ trans('cashier::messages.secured_transaction') }}</span>
                            </span>
                        </div>
                        <div class="mb-4">
                            <label class="">{{ trans('cashier::messages.total_amount') }}</label>
                            <h2>{{ number_format($invoice->total(), 2) }}  ({{ $invoice->getCurrencyCode() }})</h2>
                        </div>
                        <label class="">{{ trans('cashier::messages.items') }}</label>
                        <table class="w-100">
                            <tbody>
                                @foreach ($invoice->order->orderItems as $orderItem)
                                    <tr>
                                        <td>
                                            @if($orderItem->image_url)
                                                <div class="me-3 d-inline-block shadow-sm" style="background-color: #F0F0F0;border-radius:3px;overflow:hidden;">
                                                    <img src="{{ $orderItem->image_url }}" alt="" style="height:40px;width:40px;object-fit:contain;">
                                                </div>
                                            @endif
                                        </td>
                                        <td class="py-3 pe-4" style="width: 70%;">
                                            <p class="fw-semibold mb-1">{!! $orderItem->title !!}</p>
                                            <p class="mb-2 small">{!! $orderItem->description !!}</p>
                                            <p class="mb-0 small">{{ trans('cashier::messages.quantity') }}: <strong>1</strong></p>
                                        </td>
                                        <td class="text-end py-3">
                                            <span class="text-bold">{{ number_format($orderItem->amount, 2) }}</span><br>
                                            <span class="text-muted small">({{ $orderItem->order->currency->code }})</span>
                                        </td>
                                    </tr>
                                @endforeach

                                <tr>
                                    <td>
                                        
                                    </td>
                                    <td valign="top" class="pb-2 pe-4 border-bottom pt-4" style="width: 70%;">
                                        <p class="fw-semibold mb-1 small">{{ trans('cashier::messages.subtotal') }}</p>
                                    </td>
                                    <td class="text-end pb-2 border-bottom pt-4">
                                        <span class="text-bold">
                                            {{ number_format($invoice->total(), 2) }}
                                            ({{ $invoice->getCurrencyCode() }})
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        
                                    </td>
                                    <td class="py-3 pe-4 border-bottom small" style="width: 70%;">
                                        <p class="mb-1 text-muted2">{{ trans('cashier::messages.payment_transaction_fee') }}</p>
                                        <p class="mb-1 text-muted2">{{ trans('cashier::messages.activate_account_after_subscribing') }}</p>
                                    </td>
                                    <td valign="top" class="text-end py-3 border-bottom text-muted2"><span class="">{{ number_format($invoice->total(), 2) }}  ({{ $invoice->getCurrencyCode() }})</span></td>
                                </tr>
                                <tr>
                                    <td>
                                        
                                    </td>
                                    <td valign="top" class="py-3 pe-4" style="width: 70%;">
                                        <p class="fw-semibold mb-1 small">{{ trans('cashier::messages.total_due') }}</p>
                                    </td>
                                    <td class="text-end py-3"><span class="text-bold text-nowrap">{{ number_format($invoice->total(), 2) }}  ({{ $invoice->getCurrencyCode() }})</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Right side: Payment form -->
                <div class="col-md-6">
                    <div class="px-5">
                        @yield('content')
                    </div>
                </div>
            </div>

            <div class="mt-5">
                <hr>
                <form id="cancelForm" method="POST" action="{{ action('SubscriptionController@cancelInvoice', [
                            'invoice_uid' => $invoice->uid,
                ]) }}">
                    {{ csrf_field() }}
                    <a href="javascript:;" onclick="$('#cancelForm').submit()" class="text-dark small text-decoration-underline">
                        {{ trans('messages.subscription.cancel_now_change_other_plan') }}
                    </a>
                </form>
                
            </div>
            
        </div>
        <br />
        <br />
        <br />
        
    </body>
</html>