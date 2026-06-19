<?php

namespace App\Http\Requests;

/**
 * Stesse regole e autorizzazione di StoreCashbackRuleRequest.
 * Il default di is_active (true in creazione, false in modifica) resta nel controller.
 */
class UpdateCashbackRuleRequest extends StoreCashbackRuleRequest
{
}
