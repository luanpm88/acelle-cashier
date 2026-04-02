<?php

namespace App\Cashier\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Model\Invoice;
use App\Cashier\Services\StripePaymentGateway;
use App\Cashier\Contracts\CheckoutHandlerInterface;


/**
 * Stripe Payment Flow
 * ====================
 *
 * Overview:
 *   Main app creates StripePaymentGateway($pubKey, $secretKey) then calls getCheckoutUrl($invoice, $returnUrl).
 *   Credentials + returnUrl are encrypted into a gateway_token and embedded in the URL.
 *   This library never queries DB or depends on main app models to obtain credentials.
 *
 * Main flow (new card payment):
 *
 *   [Main App]
 *       │
 *       │  redirect user to checkout URL
 *       ▼
 *   GET /checkout ── checkout()
 *       │  - Decrypt gateway_token → extract pub_key, secret_key
 *       │  - Create Stripe SetupIntent → obtain clientSecret
 *       │  - Render card input form (Stripe Elements JS)
 *       ▼
 *   [Browser - User enters card details]
 *       │
 *       │  JS: stripe.confirmCardSetup(clientSecret)
 *       │       → Stripe returns payment_method_id
 *       │
 *       │  AJAX POST
 *       ▼
 *   POST /pay ── pay()
 *       │  - Decrypt gateway_token → extract pub_key, secret_key, return_url
 *       │  - Call Stripe API: get/create customer, retrieve payment method
 *       │  - Save payment method to DB (card info + customer_id for future auto-billing)
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
 *       │  - Decrypt gateway_token → rebuild a new checkout URL
 *       │  - Redirect back to GET /checkout so user can re-enter card / authenticate
 *       ▼
 *   (Back to main flow above)
 */
class StripeController extends Controller
{
    protected function getGatewayConfig(Request $request)
    {
        return json_decode(decrypt($request->gateway_token), true);
    }

    protected function getServiceFromConfig(array $config)
    {
        return new StripePaymentGateway($config['pub_key'], $config['secret_key']);
    }

    /**
     * GET /cashier/stripe/checkout/{invoice_uid}?gateway_token=xxx
     *
     * Display the card input form (Stripe Elements).
     * Called when main app redirects the user here to pay an invoice.
     *
     * Input:
     *   - invoice_uid (URL path): identifies the invoice to pay
     *   - gateway_token (query string): encrypted payload containing pub_key, secret_key, return_url
     *
     * Output: HTML page with Stripe card form.
     *   Page includes clientSecret (for JS to call stripe.confirmCardSetup)
     *   and publishableKey (for initializing Stripe.js).
     *   After user confirms card, JS will AJAX POST to pay() with the payment_method_id.
     */
    public function checkout(Request $request, $invoice_uid)
    {
        $invoice = Invoice::findByUid($invoice_uid);
        $config = $this->getGatewayConfig($request);
        $service = $this->getServiceFromConfig($config);

        if (!$invoice->isNew()) {
            throw new \Exception('Invoice is not new');
        }

        return view('cashier::stripe.checkout', [
            'gatewayToken' => $request->gateway_token,
            'paymentGatewayId' => $request->payment_gateway_id,
            'returnUrl' => $request->return_url ?? '/',
            'invoice' => $invoice,
            'clientSecret' => $service->getClientSecret($invoice->customer->uid, $invoice),
            'publishableKey' => $service->getPublishableKey(),
        ]);
    }

    /**
     * POST /cashier/stripe/pay/{invoice_uid}?gateway_token=xxx
     *
     * Process payment after user has confirmed their card in the browser.
     * Called via AJAX from the checkout page (Stripe Elements JS).
     *
     * Input:
     *   - invoice_uid (URL path): identifies the invoice to pay
     *   - gateway_token (query string): encrypted payload containing pub_key, secret_key, return_url
     *   - payment_method_id (POST body): returned by Stripe JS after successful confirmCardSetup
     *
     * Processing:
     *   1. Get/create Stripe Customer from local customer uid
     *   2. Save payment method to DB (card info + customer_id for future auto-billing)
     *   3. Charge invoice via Stripe PaymentIntent, or mark as success if invoice is free
     *
     * Output: JSON { return_url: "..." } so the browser JS can redirect user back to main app.
     */
    public function pay(Request $request, $invoice_uid)
    {
        try {
            $invoice = Invoice::findByUid($invoice_uid);
            $config = $this->getGatewayConfig($request);
            $service = $this->getServiceFromConfig($config);

            $stripeCustomer = $service->getStripeCustomer($invoice->customer->uid);
            $stripePaymentMethod = $service->getPaymentMethod($request->payment_method_id);

            $autoBillingData = [
                'payment_method_id' => $request->payment_method_id, # Stripe
                'customer_id' => $stripeCustomer->id,
                'card_type' => ucfirst($stripePaymentMethod->card->brand),
                'last_4' => $stripePaymentMethod->card->last4,
                'exp_month' => $stripePaymentMethod->card->exp_month,
                'exp_year' => $stripePaymentMethod->card->exp_year,
            ];

            $handler = app(CheckoutHandlerInterface::class);

            // Main app handles DB operation (save payment method)
            $paymentMethodInfo = $handler->createPaymentMethod($invoice, $request->payment_gateway_id, $autoBillingData);

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
     * GET /cashier/stripe/{invoice_uid}/payment-auth?gateway_token=xxx
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
        $invoice = Invoice::findByUid($invoice_uid);
        $config = $this->getGatewayConfig($request);
        $service = $this->getServiceFromConfig($config);

        return redirect()->away($service->getCheckoutUrl($invoice, $request->payment_gateway_id, $request->return_url ?? '/'));
    }
}
