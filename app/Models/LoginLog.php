<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $country
 * @property string|null $city
 * @property string|null $device_type
 * @property string|null $browser
 * @property string|null $os
 * @property bool $is_new_ip
 * @property \Illuminate\Support\Carbon $logged_in_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginLog query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginLog whereBrowser($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginLog whereCity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginLog whereCountry($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginLog whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginLog whereDeviceType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginLog whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginLog whereIpAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginLog whereIsNewIp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginLog whereLoggedInAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginLog whereOs($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginLog whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginLog whereUserAgent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginLog whereUserId($value)
 * @mixin \Eloquent
 */
class LoginLog extends Model
{
    protected $fillable = [
        'user_id',
        'ip_address',
        'user_agent',
        'country',
        'city',
        'device_type',
        'browser',
        'os',
        'is_new_ip',
        'logged_in_at',
    ];

    protected $casts = [
        'logged_in_at' => 'datetime',
        'is_new_ip'    => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
