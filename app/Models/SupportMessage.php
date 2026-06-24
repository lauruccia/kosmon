<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $subject
 * @property string $body
 * @property string $status
 * @property int|null $user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupportMessage newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupportMessage newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupportMessage query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupportMessage whereBody($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupportMessage whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupportMessage whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupportMessage whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupportMessage whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupportMessage whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupportMessage whereSubject($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupportMessage whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupportMessage whereUserId($value)
 * @mixin \Eloquent
 */
class SupportMessage extends Model
{
    protected $fillable = ['name', 'email', 'subject', 'body', 'status', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool   { return $this->status === 'open'; }
    public function isResolved(): bool { return $this->status === 'resolved'; }
}
