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
 * @property string $title
 * @property string $image_path
 * @property string $target_url
 * @property int $duration_days
 * @property string|null $duration_plan
 * @property int $price_per_day
 * @property int $total_price
 * @property int|null $point_transaction_id
 * @property string $status
 * @property bool $is_visible
 * @property int $sort_order
 * @property \Carbon\Carbon|null $starts_at
 * @property \Carbon\Carbon|null $ends_at
 * @property string|null $review_note
 * @property int|null $reviewed_by
 * @property \Carbon\Carbon|null $reviewed_at
 */
class Ad extends AbstractModel
{
    protected $table = 'lowseekai_advertising_ads';

    public $timestamps = true;

    protected $casts = [
        'duration_days' => 'integer',
        'duration_plan' => 'string',
        'price_per_day' => 'integer',
        'total_price' => 'integer',
        'point_transaction_id' => 'integer',
        'is_visible' => 'boolean',
        'sort_order' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'reviewed_at' => 'datetime',
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
