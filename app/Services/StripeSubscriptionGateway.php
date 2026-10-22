<?php

namespace App\Services;

use Stripe\StripeClient;

/**
 * Le sole chiamate a Stripe che servono agli abbonamenti KYCard, dietro una
 * classe sottile: i test ne mettono una finta al posto di questa, cosi' la
 * logica (webhook, rate, insoluti) si prova senza rete e senza chiavi.
 */
class StripeSubscriptionGateway
{
    private function client(): StripeClient
    {
        $secret = config('services.stripe.secret');
        if (! $secret) {
            throw new \RuntimeException('Stripe non configurato.');
        }

        return new StripeClient($secret);
    }

    /** Sessione Checkout in modalita' abbonamento. */
    public function createCheckoutSession(array $params): object
    {
        return $this->client()->checkout->sessions->create($params);
    }

    public function retrieveSubscription(string $id): object
    {
        return $this->client()->subscriptions->retrieve($id);
    }

    /**
     * Ferma l'abbonamento a una data precisa: e' il TETTO delle rate. Parte
     * dopo il primo pagamento, cosi' Stripe non puo' addebitare la rata N+1
     * nemmeno se un nostro webhook va perso.
     */
    public function setCancelAt(string $subscriptionId, int $timestamp): object
    {
        return $this->client()->subscriptions->update($subscriptionId, [
            'cancel_at'          => $timestamp,
            'proration_behavior' => 'none',
        ]);
    }

    /** Disdetta a fine periodo: l'ultimo periodo gia' pagato resta valido. */
    public function cancelAtPeriodEnd(string $subscriptionId): object
    {
        return $this->client()->subscriptions->update($subscriptionId, [
            'cancel_at_period_end' => true,
        ]);
    }

    /** Disdetta subito, senza fatture di conguaglio. */
    public function cancelNow(string $subscriptionId): object
    {
        return $this->client()->subscriptions->cancel($subscriptionId, [
            'prorate'     => false,
            'invoice_now' => false,
        ]);
    }

    /** Portale clienti Stripe: cambio carta e storico fatture. */
    public function createPortalSession(string $customerId, string $returnUrl): object
    {
        return $this->client()->billingPortal->sessions->create([
            'customer'   => $customerId,
            'return_url' => $returnUrl,
        ]);
    }
}
