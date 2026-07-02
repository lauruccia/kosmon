<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riga della closure table dell'albero agenti.
 *
 * @property int $id
 * @property int $ancestor_id
 * @property int $descendant_id
 * @property int $depth
 * @property int|null $branch_root_id  Figlio di 1° livello dell'antenato attraverso cui passa il ramo.
 * @property-read User $ancestor
 * @property-read User $descendant
 * @property-read User|null $branchRoot
 */
class MlmAgentClosure extends Model
{
    protected $table = 'mlm_agent_closure';

    protected $fillable = [
        'ancestor_id',
        'descendant_id',
        'depth',
        'branch_root_id',
    ];

    public function ancestor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ancestor_id');
    }

    public function descendant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'descendant_id');
    }

    public function branchRoot(): BelongsTo
    {
        return $this->belongsTo(User::class, 'branch_root_id');
    }
}
