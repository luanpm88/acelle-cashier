<?php

namespace App\Cashier\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Cashier\DTO\PaymentIntent;
use App\Cashier\DTO\PaymentResult;
use App\Cashier\DTO\StripeAutoBillingData;
use App\Cashier\Services\StripePaymentGateway;
use App\Cashier\Contracts\CheckoutHandlerInterface;
use App\Cashier\Contracts\PaymentGatewayResolverInterface;

/**
 * Stripe One-Off Payment Controller
 * ==================================
 *
 * Routes:
 *   GET  /cashier/stripe/checkout/{intent_uid}        — render Stripe Elements card form
 *   POST /cashier/stripe/pay/{intent_uid}             — process payment
 *   GET  /cashier/stripe/{intent_uid}/payment-auth    — re-checkout link after auto-charge 3DS
 *
 * Cashier never imports App\Model\Invoice. All state comes from PaymentIntent DTO via handler.
 *
 * Trust boundary: client supplies only intent_uid (URL) + stripe_payment_method (POST body).
 * remote_reference_id (Stripe pi_xxx) is server-stored in intent — never trust client copy.
 */
class StripeController extends Controller
{
    protected function findIntent(string $uid): ?PaymentIntent
    {
        return app(CheckoutHandlerInterface::class)->findIntent($uid);
    }

    protected function getService(PaymentIntent $intent): StripePaymentGateway
    {
        $service = app(PaymentGatewayResolverInterface::class)->resolve($intent->paymentGatewayId);

        if (!$service instanceof StripePaymentGateway) {
            throw new \Exception('Gateway mismatch: expected StripePaymentGateway');
        }
        return $service;
    }

    /**
     * GET /cashier/stripe/checkout/{intent_uid}?return_url=...
     */
    public function checkout(Request $request, string $intent_uid)
    {
        $intent = $this->findIntent($intent_uid);
        $returnUrl = $request->return_url ?? '/';

        if (!$intent) {
            return redirect()->away($returnUrl)
                ->with('alert-error', trans('cashier::messages.stripe.intent_not_found'));
        }

        // Free invoice — short-circuit (no card form to show)
        if ($intent->amount <= 0) {
            return redirect()->away($returnUrl)
                ->with('alert-success', trans('cashier::messages.already_paid'));
        }

        $service = $this->getService($intent);

        return view('cashier::stripe.checkout', [
            'intent'         => $intent,
            'returnUrl'      => $returnUrl,
            'clientSecret'   => $service->getClientSecret($intent->payer->uid),
            'publishableKey' => $service->getPublishableKey(),
        ]);
    }

    /**
     * POST /cashier/stripe/pay/{intent_uid}
     */
    public function pay(Request $request, string $intent_uid)
    {
        try {
            $intent = $this->findIntent($intent_uid);
            if (!$intent) {
                return response()->json(['error' => trans('cashier::messages.stripe.intent_not_found')], 404);
            }

            $returnUrl = $request->return_url ?? '/';
            $service = $this->getService($intent);
            $handler = app(CheckoutHandlerInterface::class);

            if (empty($request->stripe_payment_method)) {
                return response()->json(['error' => 'Payment method ID is required'], 422);
            }

            $stripeCustomer = $service->getStripeCustomer($intent->payer->uid);
            $pm = $service->getPaymentMethod($request->stripe_payment_method);

            $billingData = new StripeAutoBillingData([
                'stripe_payment_method' => $request->stripe_payment_method,
                'stripe_customer'       => $stripeCustomer->id,
                'card_type'             => ucfirst($pm->card->brand),
                'last_4'                => $pm->card->last4,
                'exp_month'             => $pm->card->exp_month,
                'exp_year'              => $pm->card->exp_year,
            ]);

            $pmInfo = $handler->createPaymentMethod($intent, $billingData->toArray());

            $result = $service->autoCharge($intent, $billingData->toArray());

            return $this->dispatchResult($result, $intent, $pmInfo, $handler, $returnUrl);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * GET /cashier/stripe/{intent_uid}/payment-auth?return_url=...
     *
     * Email link sent after off-session autoCharge hit a 3DS challenge. Re-render the
     * checkout form so the user can re-confirm the card (or use a fresh one).
     */
    public function paymentAuth(Request $request, string $intent_uid)
    {
        $intent = $this->findIntent($intent_uid);
        $returnUrl = $request->return_url ?? '/';

        if (!$intent) {
            return redirect()->away($returnUrl)
                ->with('alert-error', trans('cashier::messages.stripe.intent_not_found'));
        }

        $service = $this->getService($intent);
        return redirect()->away($service->getCheckoutUrl($intent, $returnUrl));
    }

    private function dispatchResult(
        PaymentResult $result,
        PaymentIntent $intent,
        $pmInfo,
        CheckoutHandlerInterface $handler,
        string $returnUrl
    ) {
        switch ($result->status) {
            case PaymentResult::STATUS_SUCCESS:
                $handler->onPaymentSuccess($intent, $pmInfo, $result->remoteReferenceId);
                return response()->json(['return_url' => $returnUrl]);

            case PaymentResult::STATUS_REQUIRES_ACTION:
                $handler->onPaymentRequiresAuth($intent, $result->clientSecret, $result->remoteReferenceId);
                return response()->json([
                    'requires_action' => true,
                    'client_secret'   => $result->clientSecret,
                ]);

            case PaymentResult::STATUS_FAILED:
            default:
                $handler->onPaymentFailed($intent, $pmInfo, $result->error ?? 'Charge failed');
                return response()->json(['error' => $result->error ?? 'Charge failed'], 422);
        }
    }
}
