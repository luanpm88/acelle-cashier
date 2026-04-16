<?php

namespace App\Cashier\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Model\Invoice;
use App\Cashier\Services\StripePaymentGateway;
use App\Cashier\Contracts\CheckoutHandlerInterface;
use App\Cashier\Contracts\PaymentGatewayResolverInterface;
use App\Cashier\DTO\StripeAutoBillingData;


/**
 * Stripe Payment Flow
 * ====================
 *
 * Overview:
 *   Main app creates StripePaymentGateway($pubKey, $secretKey) then calls
 *   getCheckoutUrl($invoice, $paymentGatewayId, $returnUrl). The URL carries only
 *   payment_gateway_id + return_url (+ invoice_uid in path) — NO credentials.
 *
 *   The cashier controller resolves payment_gateway_id → StripePaymentGateway
 *   (with credentials loaded from DB) via PaymentGatewayResolverInterface, which
 *   the main app implements. This keeps cashier decoupled from the PaymentGateway
 *   model — it only knows the resolver + gateway interfaces.
 *
 * Main flow (new card payment):
 *
 *   [Main App]
 *       │
 *       │  redirect user to checkout URL
 *       ▼
 *   GET /checkout ── checkout()
 *       │  - Resolve payment_gateway_id → StripePaymentGateway (credentials from DB)
 *       │  - Create Stripe SetupIntent → obtain clientSecret
 *       │  - Render card input form (Stripe Elements JS)
 *       ▼
 *   [Browser - User enters card details]
 *       │
 *       │  JS: stripe.confirmCardSetup(clientSecret)
 *       │       → Stripe returns stripe_payment_method
 *       │
 *       │  AJAX POST
 *       ▼
 *   POST /pay ── pay()
 *       │  - Resolve payment_gateway_id → StripePaymentGateway
 *       │  - Read return_url from POST body
 *       │  - Call Stripe API: get/create customer, retrieve payment method
 *       │  - Delegate to CheckoutHandlerInterface::createPaymentMethod() (main app saves to DB)
 *       │  - Charge invoice via Stripe PaymentIntent (or mark success if free)
 *       │  - Return JSON { return_url: "..." }
 *       ▼
 *   [Browser]
 *       │
 *       │  JS: window.location = response.return_url
 *       ▼
 *   [Main App - success page]
 *
 *
 * 3D Secure / SCA authentication flow:
 *
 *   When autoCharge() in pay() throws CardException (card requires 3D Secure verification):
 *       │  - Invoice is marked as payFailed with a payment-auth link
 *       │  - User receives the link via email/notification
 *       ▼
 *   GET /payment-auth ── paymentAuth()
 *       │  - Rebuild a fresh checkout URL (only payment_gateway_id + return_url)
 *       │  - Redirect back to GET /checkout so user can re-enter card / authenticate
 *       ▼
 *   (Back to main flow above)
 */
class StripeController extends Controller
{
    protected function findInvoice(string $invoiceUid): ?Invoice
    {
        return Invoice::findByUid($invoiceUid);
    }

    /**
     * Resolve payment_gateway_id from the request into a ready-to-use StripePaymentGateway.
     *
     * Credentials are loaded from DB via the resolver — cashier has no knowledge of
     * how the main app stores them. This replaces the previous gateway_token pattern
     * (encrypted credentials in URL) with a clean DI lookup.
     */
    protected function getService(Request $request): StripePaymentGateway
    {
        $service = app(PaymentGatewayResolverInterface::class)
            ->resolve($request->payment_gateway_id);

        if (!$service instanceof StripePaymentGateway) {
            throw new \Exception('Invalid payment gateway for Stripe checkout');
        }

        return $service;
    }

    /**
     * GET /cashier/stripe/checkout/{invoice_uid}?payment_gateway_id=xxx&return_url=xxx
     *
     * Display the card input form (Stripe Elements).
     * Called when main app redirects the user here to pay an invoice.
     *
     * Input:
     *   - invoice_uid (URL path): identifies the invoice to pay
     *   - payment_gateway_id (query string): UID of the payment gateway (user's choice at checkout UI)
     *   - return_url (query string): URL to redirect after payment
     *
     * Output: HTML page with Stripe card form.
     *   Page includes clientSecret (for JS to call stripe.confirmCardSetup)
     *   and publishableKey (for initializing Stripe.js).
     *   After user confirms card, JS will AJAX POST to pay() with the stripe_payment_method.
     */
    public function checkout(Request $request, $invoice_uid)
    {
        $invoice = $this->findInvoice($invoice_uid);
        $service = $this->getService($request);

        if (!$invoice) {
            return redirect()->away($request->return_url ?? '/')
                ->with('alert-error', 'Invoice not found.');
        }

        if (!$invoice->isNew()) {
            throw new \Exception('Invoice is not new');
        }

        return view('cashier::stripe.checkout', [
            'paymentGatewayId' => $request->payment_gateway_id,
            'returnUrl' => $request->return_url ?? '/',
            'invoice' => $invoice,
            'clientSecret' => $service->getClientSecret($invoice->getPayerUid()),
            'publishableKey' => $service->getPublishableKey(),
        ]);
    }

    /**
     * POST /cashier/stripe/pay/{invoice_uid}
     *
     * Process payment after user has confirmed their card in the browser.
     * Called via AJAX from the checkout page (Stripe Elements JS).
     *
     * Input:
     *   - invoice_uid (URL path): identifies the invoice to pay
     *   - stripe_payment_method (POST body): returned by Stripe JS after successful confirmCardSetup
     *   - payment_gateway_id (POST body): UID of the payment gateway (for resolver + creating payment method record)
     *   - return_url (POST body): URL to redirect after payment
     *
     * Processing:
     *   1. Resolve payment_gateway_id → StripePaymentGateway
     *   2. Get/create Stripe Customer from local payer uid
     *   3. Delegate to CheckoutHandlerInterface::createPaymentMethod() (main app saves to DB)
     *   4. Charge invoice via Stripe PaymentIntent, or mark as success if invoice is free
     *
     * Output: JSON { return_url: "..." } so the browser JS can redirect user back to main app.
     */
    public function pay(Request $request, $invoice_uid)
    {
        try {
            $invoice = $this->findInvoice($invoice_uid);
            $service = $this->getService($request);

            if (!$invoice) {
                return response()->json([
                    'message' => 'Invoice not found.',
                ], 404);
            }

            if (!$invoice->isNew()) {
                return response()->json([
                    'message' => 'Invoice is not new',
                ], 422);
            }

            $stripeCustomer = $service->getStripeCustomer($invoice->getPayerUid());
            $stripePaymentMethod = $service->getPaymentMethod($request->stripe_payment_method);

            // Validate + structure the billing data upstream via DTO (throws if required fields missing).
            $autoBillingData = new StripeAutoBillingData([
                'stripe_payment_method' => $request->stripe_payment_method,
                'stripe_customer' => $stripeCustomer->id,
                'card_type' => ucfirst($stripePaymentMethod->card->brand),
                'last_4' => $stripePaymentMethod->card->last4,
                'exp_month' => $stripePaymentMethod->card->exp_month,
                'exp_year' => $stripePaymentMethod->card->exp_year,
            ]);

            $handler = app(CheckoutHandlerInterface::class);

            // Main app handles DB operation (save payment method)
            $paymentMethodInfo = $handler->createPaymentMethod($invoice, $request->payment_gateway_id, $autoBillingData->toArray());

            // Library handles Stripe charge, notifies main app of result
            $service->autoCharge($invoice, $paymentMethodInfo);

            return response()->json([
                'return_url' => $request->return_url ?? '/',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * GET /cashier/stripe/{invoice_uid}/payment-auth?payment_gateway_id=xxx&return_url=xxx
     *
     * Handle 3D Secure / SCA re-authentication.
     * Called when a previous autoCharge() failed due to CardException (card requires verification).
     * The user receives this link via email/notification.
     *
     * Simply rebuilds a fresh checkout URL and redirects user back to checkout()
     * so they can re-enter or authenticate their card.
     */
    public function paymentAuth(Request $request, $invoice_uid)
    {
        $invoice = $this->findInvoice($invoice_uid);
        $service = $this->getService($request);

        if (!$invoice) {
            return redirect()->away($request->return_url ?? '/')
                ->with('alert-error', 'Invoice not found.');
        }

        return redirect()->away($service->getCheckoutUrl($invoice, $request->payment_gateway_id, $request->return_url ?? '/'));
    }
}
