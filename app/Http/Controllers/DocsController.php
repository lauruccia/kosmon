<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DocsController extends Controller
{
    /**
     * GET /docs/api
     * Documentazione interattiva dell'API REST v1.
     */
    public function apiDocs(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        abort_if($user->canAccessBackoffice(), 403);

        return view('portal.docs-api', [
            'pageTitle' => 'Documentazione API v1',
            'activeNav' => 'docs-api',
        ]);
    }
    public function openApiJson(): \Illuminate\Http\JsonResponse
    {
        $spec = [
            'openapi' => '3.0.3',
            'info' => [
                'title'       => 'KMoney API v1',
                'description' => 'API REST pubblica del circuito KMoney. Autenticazione Bearer token (prefix km_).',
                'version'     => '1.0.0',
                'contact'     => ['email' => config('mail.from.address')],
            ],
            'servers' => [
                ['url' => config('app.url') . '/api/v1', 'description' => 'Produzione'],
            ],
            'security' => [['BearerAuth' => []]],
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'km_{token}'],
                ],
                'schemas' => [
                    'Transfer' => [
                        'type' => 'object',
                        'properties' => [
                            'uuid'          => ['type' => 'string', 'format' => 'uuid'],
                            'amount'        => ['type' => 'integer', 'description' => 'Importo in KY (interi)'],
                            'currency_code' => ['type' => 'string', 'example' => 'KY'],
                            'kind'          => ['type' => 'string', 'example' => 'api_payment'],
                            'status'        => ['type' => 'string', 'enum' => ['booked', 'pending', 'rejected']],
                            'reference'     => ['type' => 'string'],
                            'description'   => ['type' => 'string'],
                            'booked_at'     => ['type' => 'string', 'format' => 'date-time'],
                        ],
                    ],
                    'Account' => [
                        'type' => 'object',
                        'properties' => [
                            'id'                => ['type' => 'integer'],
                            'account_number'    => ['type' => 'string'],
                            'display_name'      => ['type' => 'string'],
                            'available_balance' => ['type' => 'integer'],
                            'currency_code'     => ['type' => 'string'],
                            'status'            => ['type' => 'string'],
                        ],
                    ],

                    'Balance' => [
                        'type' => 'object',
                        'properties' => [
                            'account_number'         => ['type' => 'string'],
                            'currency'               => ['type' => 'string', 'example' => 'KY'],
                            'balance'                => ['type' => 'integer', 'description' => 'Saldo contabile in centesimi KY'],
                            'credit_limit'           => ['type' => 'integer', 'description' => 'Fido attivo in centesimi KY'],
                            'available_balance'      => ['type' => 'integer', 'description' => 'Saldo disponibile (balance + credit_limit)'],
                            'max_balance'            => ['type' => 'integer', 'nullable' => true, 'description' => 'Tetto massimo (null = nessun tetto)'],
                            'is_in_debit'            => ['type' => 'boolean'],
                            'is_at_ceiling'          => ['type' => 'boolean'],
                            'can_sell'               => ['type' => 'boolean'],
                            'allowed_ky_percentages' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        ],
                    ],
                    'PaymentPlan' => [
                        'type' => 'object',
                        'properties' => [
                            'uuid'               => ['type' => 'string', 'format' => 'uuid'],
                            'status'             => ['type' => 'string', 'enum' => ['active', 'pending_approval', 'completed', 'cancelled', 'rejected']],
                            'role'               => ['type' => 'string', 'enum' => ['debtor', 'creditor']],
                            'total_amount'       => ['type' => 'integer'],
                            'currency'           => ['type' => 'string', 'example' => 'KY'],
                            'installments_count' => ['type' => 'integer'],
                            'frequency'          => ['type' => 'string', 'enum' => ['monthly', 'weekly', 'biweekly']],
                            'first_due_date'     => ['type' => 'string', 'format' => 'date'],
                            'description'        => ['type' => 'string'],
                            'debtor'             => ['type' => 'object'],
                            'creditor'           => ['type' => 'object'],
                            'installments'       => ['type' => 'array', 'items' => ['type' => 'object']],
                            'created_at'         => ['type' => 'string', 'format' => 'date-time'],
                        ],
                    ],
                    'PaymentRequest' => [
                        'type' => 'object',
                        'properties' => [
                            'uuid'          => ['type' => 'string', 'format' => 'uuid'],
                            'status'        => ['type' => 'string', 'enum' => ['pending', 'paid', 'expired', 'cancelled']],
                            'direction'     => ['type' => 'string', 'enum' => ['incoming', 'outgoing']],
                            'kind'          => ['type' => 'string', 'enum' => ['qr_dynamic', 'nfc', 'link', 'text', 'ecommerce']],
                            'amount'        => ['type' => 'integer'],
                            'currency'      => ['type' => 'string', 'example' => 'KY'],
                            'description'   => ['type' => 'string'],
                            'external_reference' => ['type' => 'string', 'nullable' => true, 'description' => 'Riferimento ordine lato negoziante (es. numero ordine WooCommerce/Magento)'],
                            'pay_url'       => ['type' => 'string', 'nullable' => true, 'description' => 'URL hosted checkout a cui reindirizzare il cliente; presente solo se status=pending'],
                            'expires_at'    => ['type' => 'string', 'format' => 'date-time'],
                            'paid_at'       => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                            'creditor'      => ['type' => 'object'],
                            'payer'         => ['type' => 'object', 'nullable' => true],
                            'transfer_uuid' => ['type' => 'string', 'nullable' => true],
                            'created_at'    => ['type' => 'string', 'format' => 'date-time'],
                        ],
                    ],
                    'ErrorResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'paths' => [
                '/me' => [
                    'get' => [
                        'summary'     => 'Dati account autenticato',
                        'operationId' => 'getMe',
                        'tags'        => ['Account'],
                        'responses'   => [
                            '200' => ['description' => 'Account corrente', 'content' => ['application/json' => ['schema' => ['\$ref' => '#/components/schemas/Account']]]],
                            '401' => ['description' => 'Non autenticato'],
                        ],
                    ],
                ],

                '/balance' => [
                    'get' => [
                        'summary'     => 'Saldo dettagliato',
                        'operationId' => 'getBalance',
                        'tags'        => ['Account'],
                        'responses'   => [
                            '200' => ['description' => 'Saldo, fido, disponibile, capacita di vendita', 'content' => ['application/json' => ['schema' => ['\$ref' => '#/components/schemas/Balance']]]],
                            '401' => ['description' => 'Non autenticato'],
                            '404' => ['description' => 'Nessun conto attivo'],
                        ],
                    ],
                ],
                '/payment-plans' => [
                    'get' => [
                        'summary'     => 'Lista piani rateali',
                        'operationId' => 'listPaymentPlans',
                        'tags'        => ['PaymentPlans'],
                        'parameters'  => [
                            ['name' => 'status',   'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['active', 'pending_approval', 'completed', 'cancelled', 'rejected']]],
                            ['name' => 'role',     'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['debtor', 'creditor']]],
                            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 25, 'maximum' => 100]],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Lista paginata piani rateali'],
                            '401' => ['description' => 'Non autenticato'],
                        ],
                    ],
                ],
                '/payment-plans/{uuid}' => [
                    'get' => [
                        'summary'     => 'Dettaglio piano rateale',
                        'operationId' => 'getPaymentPlan',
                        'tags'        => ['PaymentPlans'],
                        'parameters'  => [
                            ['name' => 'uuid', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Dettaglio piano rateale', 'content' => ['application/json' => ['schema' => ['\$ref' => '#/components/schemas/PaymentPlan']]]],
                            '404' => ['description' => 'Non trovato'],
                            '401' => ['description' => 'Non autenticato'],
                        ],
                    ],
                ],
                '/payment-requests' => [
                    'get' => [
                        'summary'     => 'Lista richieste di pagamento',
                        'operationId' => 'listPaymentRequests',
                        'tags'        => ['PaymentRequests'],
                        'parameters'  => [
                            ['name' => 'status',    'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['pending', 'paid', 'expired', 'cancelled']]],
                            ['name' => 'direction', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['incoming', 'outgoing']]],
                            ['name' => 'per_page',  'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 25, 'maximum' => 100]],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Lista paginata richieste di pagamento'],
                            '401' => ['description' => 'Non autenticato'],
                        ],
                    ],
                    'post' => [
                        'summary'     => 'Crea una richiesta di pagamento hosted (e-commerce)',
                        'description' => 'Crea una PaymentRequest a carico del conto del negoziante autenticato e restituisce pay_url, l\'URL su cui reindirizzare il cliente per completare il pagamento con le proprie credenziali KMoney (2FA/passkey inclusi). L\'esito arriva via webhook payment_request.paid oppure interrogando GET /payment-requests/{uuid}.',
                        'operationId' => 'createPaymentRequest',
                        'tags'        => ['PaymentRequests'],
                        'requestBody' => [
                            'required' => true,
                            'content'  => [
                                'application/json' => [
                                    'schema' => [
                                        'type'       => 'object',
                                        'required'   => ['amount'],
                                        'properties' => [
                                            'amount'              => ['type' => 'integer', 'minimum' => 1, 'description' => 'Importo in centesimi di KY'],
                                            'description'         => ['type' => 'string', 'maxLength' => 255],
                                            'external_reference'  => ['type' => 'string', 'maxLength' => 191, 'description' => 'Numero ordine lato negoziante, usato per idempotenza sui retry'],
                                            'return_url'          => ['type' => 'string', 'format' => 'uri', 'maxLength' => 500],
                                            'cancel_url'          => ['type' => 'string', 'format' => 'uri', 'maxLength' => 500],
                                            'expires_in_minutes'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1440, 'default' => 30],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '201' => ['description' => 'Richiesta creata', 'content' => ['application/json' => ['schema' => ['\$ref' => '#/components/schemas/PaymentRequest']]]],
                            '200' => ['description' => 'Richiesta pending già esistente con lo stesso external_reference + amount (idempotenza)'],
                            '403' => ['description' => 'Token senza ability write'],
                            '422' => ['description' => 'Errore di validazione', 'content' => ['application/json' => ['schema' => ['\$ref' => '#/components/schemas/ErrorResponse']]]],
                            '401' => ['description' => 'Non autenticato'],
                        ],
                    ],
                ],
                '/payment-requests/{uuid}' => [
                    'get' => [
                        'summary'     => 'Dettaglio richiesta di pagamento',
                        'operationId' => 'getPaymentRequest',
                        'tags'        => ['PaymentRequests'],
                        'parameters'  => [
                            ['name' => 'uuid', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Dettaglio richiesta di pagamento', 'content' => ['application/json' => ['schema' => ['\$ref' => '#/components/schemas/PaymentRequest']]]],
                            '404' => ['description' => 'Non trovato'],
                            '401' => ['description' => 'Non autenticato'],
                        ],
                    ],
                ],
                '/transfers' => [
                    'get' => [
                        'summary'     => 'Lista trasferimenti',
                        'operationId' => 'listTransfers',
                        'tags'        => ['Transfers'],
                        'parameters'  => [
                            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 25]],
                            ['name' => 'status',   'in' => 'query', 'schema' => ['type' => 'string']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Lista trasferimenti paginata'],
                            '401' => ['description' => 'Non autenticato'],
                        ],
                    ],
                    'post' => [
                        'summary'     => 'Crea un trasferimento',
                        'operationId' => 'createTransfer',
                        'tags'        => ['Transfers'],
                        'requestBody' => [
                            'required' => true,
                            'content'  => [
                                'application/json' => [
                                    'schema' => [
                                        'type'       => 'object',
                                        'required'   => ['to_account', 'amount', 'idempotency_key'],
                                        'properties' => [
                                            'to_account'      => ['type' => 'string', 'description' => 'Numero conto KY pubblico del destinatario'],
                                            'amount'          => ['type' => 'integer', 'minimum' => 1],
                                            'description'     => ['type' => 'string'],
                                            'idempotency_key' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 100],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '201' => ['description' => 'Trasferimento creato'],
                            '422' => ['description' => 'Errore validazione o fondi insufficienti', 'content' => ['application/json' => ['schema' => ['\$ref' => '#/components/schemas/ErrorResponse']]]],
                            '401' => ['description' => 'Non autenticato'],
                        ],
                    ],
                ],
                '/transfers/{uuid}' => [
                    'get' => [
                        'summary'     => 'Dettaglio trasferimento',
                        'operationId' => 'getTransfer',
                        'tags'        => ['Transfers'],
                        'parameters'  => [
                            ['name' => 'uuid', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Dettaglio trasferimento', 'content' => ['application/json' => ['schema' => ['\$ref' => '#/components/schemas/Transfer']]]],
                            '404' => ['description' => 'Non trovato'],
                            '401' => ['description' => 'Non autenticato'],
                        ],
                    ],
                ],
            ],
        ];

        return response()->json($spec);
    }

}
