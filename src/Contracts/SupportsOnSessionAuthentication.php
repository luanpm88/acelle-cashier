<?php

namespace App\Cashier\Contracts;

/**
 * A gateway that can hand back the credential needed to finish an authentication challenge
 * ON-SESSION, in the buyer's own browser, instead of sending them to a hosted page.
 *
 * Why this is a separate capability and not part of the invoice contracts: reading it costs an
 * extra expand on Stripe's side, and it only ever means something for an invoice the provider is
 * still holding (`open`, awaiting 3-D Secure), and the invoice LIST contracts must not pay for an
 * expand they never use — {@see
 * SupportsRemoteCatalogInterface::getRemoteInvoices} already sits at Stripe's 4-level expand limit.
 *
 * KNOWN COUPLING — accepted, not overlooked (2026-08-07). This contract abstracts the SERVER half
 * only. Two things still assume Stripe and live outside it:
 *   1. the caller reads the browser key as gatewayData('publishable_key'), a Stripe-shaped field
 *      name it has to guess, because this interface hands back the secret alone;
 *   2. the page hard-codes js.stripe.com and the Stripe(...) / handleNextAction(...) call shape.
 * (2) is irreducible without a per-vendor JS adapter — every PSP ships its own SDK — but (1) is
 * not: widening the return to a small DTO (sdkUrl + publicKey + clientSecret) would let the vendor
 * declare both and leave the caller vendor-blind. Deliberately deferred while Stripe is the only
 * gateway implementing this. A SECOND implementer is the trigger to do it, and the first sign of
 * trouble will be that implementer having no 'publishable_key' field.
 *
 * The credential is deliberately typed as an opaque string: it is a Stripe client secret today,
 * but the contract only promises "the thing this vendor's browser SDK needs to complete the
 * pending action". Callers must treat it as a bearer credential scoped to ONE payment — safe to
 * put on the page for the buyer it belongs to, never to log or share.
 */
interface SupportsOnSessionAuthentication
{
    /**
     * The browser-side credential for finishing this invoice's pending authentication.
     *
     * A NON-NULL result does NOT mean authentication is required. Stripe hands back a secret for
     * an already-PAID invoice too (measured), so this answers "what would the browser SDK need",
     * never "is anything pending". Decide that from the invoice/payment status, before calling.
     *
     * @param  string  $remoteInvoiceId  the provider's invoice id (in_… on Stripe)
     * @return string|null  null when the provider has no such credential for this invoice at all
     *                      (e.g. nothing was ever charged against it). Callers MUST handle null by
     *                      falling back to the hosted page rather than guessing.
     */
    public function getInvoiceConfirmationSecret(string $remoteInvoiceId): ?string;
}
