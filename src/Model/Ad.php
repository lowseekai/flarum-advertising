<?php

declare(strict_types=1);

namespace Lowseekai\Advertising\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $slot_key
 * @property int|null $slot_position
 * @property bool $is_reservation
 * @property string $title
 * @property string $image_path
 * @property string $target_url
 * @property int $duration_days
 * @property string|null $duration_plan
 * @property int $price_per_day
 * @property int $total_price
 * @property int|null $point_transaction_id
 * @property int|null $refund_transaction_id
 * @property bool $auto_renew_enabled
 * @property int|null $auto_renew_price
 * @property string $auto_renew_status
 * @property \Carbon\Carbon|null $auto_renew_last_attempt_at
 * @property string|null $auto_renew_failure_reason
 * @property \Carbon\Carbon|null $auto_renew_disabled_at
 * @property string $status
 * @property bool $is_visible
 * @property int $sort_order
 * @property \Carbon\Carbon|null $starts_at
 * @property \Carbon\Carbon|null $ends_at
 * @property string|null $review_note
 * @property int|null $reviewed_by
 * @property \Carbon\Carbon|null $reviewed_at
 * @property \Carbon\Carbon|null $reserved_at
 * @property \Carbon\Carbon|null $reservation_estimated_start_at
 * @property \Carbon\Carbon|null $reservation_wait_until
 * @property int $reservation_deferred_count
 * @property \Carbon\Carbon|null $reservation_cancelled_at
 */
class Ad extends AbstractModel
{
    protected $table = 'lowseekai_advertising_ads';

    public $timestamps = true;

    protected $casts = [
        'duration_days' => 'integer',
        'duration_plan' => 'string',
        'slot_position' => 'integer',
        'is_reservation' => 'boolean',
        'price_per_day' => 'integer',
        'total_price' => 'integer',
        'point_transaction_id' => 'integer',
        'refund_transaction_id' => 'integer',
        'auto_renew_enabled' => 'boolean',
        'auto_renew_price' => 'integer',
        'auto_renew_status' => 'string',
        'auto_renew_last_attempt_at' => 'datetime',
        'auto_renew_disabled_at' => 'datetime',
        'is_visible' => 'boolean',
        'sort_order' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'reserved_at' => 'datetime',
        'reservation_estimated_start_at' => 'datetime',
        'reservation_wait_until' => 'datetime',
        'reservation_deferred_count' => 'integer',
        'reservation_cancelled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
