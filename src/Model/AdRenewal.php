<?php

declare(strict_types=1);

namespace Lowseekai\Advertising\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $ad_id
 * @property int $user_id
 * @property string $cycle_key
 * @property int $amount
 * @property int $duration_days
 * @property string $status
 * @property int|null $point_transaction_id
 * @property \Carbon\Carbon|null $previous_ends_at
 * @property \Carbon\Carbon|null $new_ends_at
 * @property string|null $failure_reason
 * @property \Carbon\Carbon|null $attempted_at
 */
class AdRenewal extends AbstractModel
{
    protected $table = 'lowseekai_advertising_renewals';

    protected $casts = [
        'amount' => 'integer',
        'duration_days' => 'integer',
        'point_transaction_id' => 'integer',
        'previous_ends_at' => 'datetime',
        'new_ends_at' => 'datetime',
        'attempted_at' => 'datetime',
    ];

    protected $guarded = [];

    public function ad(): BelongsTo
    {
        return $this->belongsTo(Ad::class, 'ad_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
