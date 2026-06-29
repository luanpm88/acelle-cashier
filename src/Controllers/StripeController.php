<?php

namespace App\Cashier\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Cashier\DTO\PaymentIntent;
use App\Cashier\Services\StripeGateway;
use App\Cashier\Contracts\CheckoutHandlerInterface;
use App\Cashier\Contracts\PaymentGatewayResolverInterface;

/**
 * Stripe controller — Stripe ALWAYS uses its HOSTED Checkout page (no on-site card form). The only
 * surviving route is the off-session-3DS re-auth landing.
 *
 *   GET /cashier/stripe/{intent_uid}/payment-auth — re-pay an off-session-declined renewal via a
 *                                                    fresh hosted Checkout Session.
 *
 * Cashier never imports App\Model\Invoice. All state comes from the PaymentIntent DTO via the handler.
 */
class StripeController extends Controller
{
    protected function findIntent(string $uid): ?PaymentIntent
    {
        // return App\Cashier\DTO\PaymentIntent (DTO, not DB record)
        return app(CheckoutHandlerInterface::class)->findIntent($uid);
    }

    protected function getService(PaymentIntent $intent): StripeGateway
    {
        $service = app(PaymentGatewayResolverInterface::class)->resolve($intent->paymentGatewayId);

        if (!$service instanceof StripeGateway) {
            throw new \Exception('Gateway mismatch: expected StripeGateway');
        }
        return $service;
    }

    /**
     * GET /cashier/stripe/{intent_uid}/payment-auth?return_url=...
     *
     * Email link sent after an off-session autoCharge hit a 3DS challenge. Stripe has no on-site
     * form anymore, so re-collect payment by redirecting the buyer to a FRESH hosted Checkout
     * Session for the (renewal) intent.
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
        return redirect()->away($service->getCheckoutUrl($intent, $returnUrl)->url);
    }
}
