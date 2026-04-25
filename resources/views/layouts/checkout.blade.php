<html lang="en">
    <head>
        <title>{{ trans('cashier::messages.stripe.checkout.page_title') }}</title>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
        <script type="text/javascript" src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
        <link rel="stylesheet" href="{{ \App\Cashier\Cashier::public_url('/vendor/acelle-cashier/css/main.css') }}">

        <meta name="viewport" content="width=device-width, initial-scale=1" />

        @include('layouts.core._includes')
        @include('layouts.core._script_vars')

        <script src="https://js.stripe.com/v3/"></script>
    </head>

    <body class="py-4">
        <div class="px-5 py-3" style="max-width:1200px;margin:auto;">
            <div class="row">
                <!-- Left side: Intent summary -->
                <div class="col-md-6">
                    <div class="bg-light shadow-sm rounded px-4 py-4">
                        <div class="mb-5">
                            <span>
                                <span class="badge badge-light bg-dark">{{ trans('cashier::messages.secured_transaction') }}</span>
                            </span>
                        </div>

                        <div class="mb-4">
                            <label class="">{{ trans('cashier::messages.total_amount') }}</label>
                            <h2>{{ number_format($intent->amount, 2) }} ({{ $intent->currency }})</h2>
                        </div>

                        <p class="text-muted small mb-0">
                            {{ $intent->description }}
                        </p>
                    </div>
                </div>

                <!-- Right side: Payment form -->
                <div class="col-md-6">
                    <div class="px-5">
                        @yield('content')
                    </div>
                </div>
            </div>

            @if (!empty($returnUrl))
            <div class="mt-5">
                <hr>
                <a href="{{ $returnUrl }}" class="text-dark small text-decoration-underline">
                    {{ trans('cashier::messages.go_back') }}
                </a>
            </div>
            @endif
        </div>
        <br /><br /><br />
    </body>
</html>
