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
                                        'required'   => ['to_account_id', 'amount', 'idempotency_key'],
                                        'properties' => [
                                            'to_account_id'   => ['type' => 'integer'],
                                            'amount'          => ['type' => 'integer', 'minimum' => 1],
                                            'description'     => ['type' => 'string'],
                                            'idempotency_key' => ['type' => 'string'],
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
