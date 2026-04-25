<?php

namespace App\Cashier\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Cashier\DTO\PaymentIntent;
use App\Cashier\Services\OfflinePaymentGateway;
use App\Cashier\Contracts\CheckoutHandlerInterface;
use App\Cashier\Contracts\PaymentGatewayResolverInterface;

/**
 * Offline payment controller.
 *
 * Routes:
 *   GET  /cashier/offline/checkout/{intent_uid}    — show payment instructions + claim button
 *   POST /cashier/offline/claim/{intent_uid}       — user claims they paid, intent stays pending
 *
 * Approval is admin-driven (separate admin UI calls SubscriptionManagementService::approvePendingInvoice).
 * Cashier never imports App\Model\Invoice.
 */
class OfflineController extends Controller
{
    protected function findIntent(string $uid): ?PaymentIntent
    {
        return app(CheckoutHandlerInterface::class)->findIntent($uid);
    }

    protected function getService(PaymentIntent $intent): OfflinePaymentGateway
    {
        $service = app(PaymentGatewayResolverInterface::class)->resolve($intent->paymentGatewayId);

        if (!$service instanceof OfflinePaymentGateway) {
            throw new \Exception('Gateway mismatch: expected OfflinePaymentGateway');
        }
        return $service;
    }

    /**
     * GET /cashier/offline/checkout/{intent_uid}?return_url=...
     */
    public function checkout(Request $request, string $intent_uid)
    {
        $intent = $this->findIntent($intent_uid);
        $returnUrl = $request->return_url ?? '/';

        if (!$intent) {
            return redirect()->away($returnUrl)
                ->with('alert-error', trans('cashier::messages.offline.intent_not_found'));
        }

        $service = $this->getService($intent);

        return view('cashier::offline.checkout', [
            'intent'              => $intent,
            'paymentInstruction'  => $service->getPaymentInstruction(),
            'returnUrl'           => $returnUrl,
        ]);
    }

    /**
     * POST /cashier/offline/claim/{intent_uid}
     *
     * User clicks "Claim payment". Server records the claim by:
     *   1. Creating a PaymentMethod row (no autobilling data — offline doesn't recur)
     *   2. Annotating intent metadata with claimed_at timestamp
     * Intent stays at status=pending. Admin approves/rejects later via admin UI.
     */
    public function claim(Request $request, string $intent_uid)
    {
        try {
            $intent = $this->findIntent($intent_uid);
            if (!$intent) {
                return redirect()->away($request->return_url ?? '/')
                    ->with('alert-error', trans('cashier::messages.offline.intent_not_found'));
            }

            $handler = app(CheckoutHandlerInterface::class);

            // Offline has no card — skip persisting a PaymentMethod row.
            // (Card-based gateways call createPaymentMethod here; offline doesn't need it.)
            // $handler->createPaymentMethod($intent, ['type' => 'offline']);

            $handler->onOfflineClaimReceived($intent);

            return redirect()->away($request->return_url ?? '/')
                ->with('alert-success', trans('cashier::messages.offline.claim_received'));
        } catch (\Throwable $e) {
            return redirect()->away($request->return_url ?? '/')
                ->with('alert-error', $e->getMessage());
        }
    }
}
