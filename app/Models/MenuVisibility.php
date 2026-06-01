<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MenuVisibility extends Model
{
    protected $fillable = [
        'menu_item_key',
        'scope_type',   // global | account_type | company | user
        'account_type', // private | company (solo quando scope_type = account_type)
        'scope_id',     // company_id o user_id (solo quando scope_type = company/user)
        'visible',
    ];

    protected $casts = [
        'visible' => 'boolean',
    ];

    // -----------------------------------------------------------------------
    // Costanti scope
    // -----------------------------------------------------------------------
    const SCOPE_GLOBAL       = 'global';
    const SCOPE_ACCOUNT_TYPE = 'account_type';
    const SCOPE_COMPANY      = 'company';
    const SCOPE_USER         = 'user';

    // -----------------------------------------------------------------------
    // Definizione centralizzata di tutte le voci del menu utente
    // -----------------------------------------------------------------------
    public static function menuItems(): array
    {
        return [
            // ── NAVIGAZIONE PRINCIPALE ──────────────────────────────────
            'conto'              => 'Conto',
            'movimenti'          => 'Movimenti',
            'richieste'          => 'Richieste',
            'wallet'             => 'KY Wallet',
            'incasso-qr'         => 'Incassa QR',
            'incasso-nfc'        => 'Incassa NFC',
            'incasso-sonic'      => 'Incassa Sonic',
            'paga-sonic'         => 'Paga Sonic',
            'incasso-codice'     => 'Incassa Codice',
            'paga-codice'        => 'Paga Codice',
            'nfc-cards'          => 'Card NFC',
            'scheduled-payments' => 'Pag. programmati',
            'webhooks'           => 'Webhook',
            'api-tokens'         => 'Token API',
            'docs-api'           => 'Docs API',
            'link-pagamento'     => 'Link pagamento',
            'rate'               => 'Rate',
            'fido'               => 'Fido',
            'ky-cards'           => 'Ricarica KY',
            'netting'            => 'Compensazione',
            'sottoconti'         => 'Sottoconti',
            'aziende'            => 'Directory aziende',
            'annunci'            => 'Annunci',
            'shop'               => 'Shop',
            'operatore'          => 'Operatore broker',
            'help'               => 'Assistenza',
            // ── SEZIONE UTENTE (in fondo alla sidebar) ──────────────────
            'profile'            => 'Profilo azienda',
            'security'           => 'Sicurezza',
            'login-logs'         => 'Accessi',
            'balance-alerts'     => 'Avvisi saldo',
            'beneficiari'        => 'Beneficiari',
            'notifications'      => 'Notifiche',
            'email-change'       => 'Cambia email',
        ];
    }
}
