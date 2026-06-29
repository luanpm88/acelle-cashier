<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <title>{{ trans('cashier::messages.stripe.checkout.page_title') }}</title>

        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
        <script type="text/javascript" src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
        <link rel="stylesheet" href="{{ \App\Cashier\Cashier::public_url('/vendor/acelle-cashier/css/main.css') }}">

        @include('layouts.core._includes')
        @include('layouts.core._script_vars')

        <script src="https://js.stripe.com/v3/"></script>
    </head>

    <body class="co-body">
        <div class="co-wrap">
            <div class="co-shell">

                {{-- LEFT: order summary --}}
                <aside class="co-summary">
                    <div class="co-brandrow">
                        <span class="co-secure">
                            <svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M4.5 7V5a3.5 3.5 0 1 1 7 0v2H12a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1h.5Zm1.5 0h4V5a2 2 0 1 0-4 0v2Z"/></svg>
                            {{ trans('cashier::messages.secured_transaction') }}
                        </span>
                    </div>

                    <div class="co-amount-label">{{ trans('cashier::messages.total_due') }}</div>
                    <h1 class="co-amount">{{ number_format($intent->amount, 2) }}<span class="co-ccy">{{ $intent->currency }}</span></h1>

                    @if (!empty($intent->description))
                        <div class="co-desc">{{ $intent->description }}</div>
                    @endif

                    <div class="co-summary-foot">
                        <svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M4.5 7V5a3.5 3.5 0 1 1 7 0v2H12a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1h.5Zm1.5 0h4V5a2 2 0 1 0-4 0v2Z"/></svg>
                        {{ trans('cashier::messages.secured_transaction') }}
                    </div>
                </aside>

                {{-- RIGHT: payment form --}}
                <main class="co-pay">
                    @yield('content')
                </main>

            </div>

            @if (!empty($returnUrl))
                <div class="co-back">
                    <a href="{{ $returnUrl }}">&larr; {{ trans('cashier::messages.go_back') }}</a>
                </div>
            @endif
        </div>
    </body>
</html>
