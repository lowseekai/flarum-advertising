<?php

declare(strict_types=1);

namespace Lowseekai\Advertising\Support;

use Carbon\Carbon;
use Flarum\Foundation\ValidationException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Lowseekai\Advertising\Model\Ad;
use Ramon\PointSystem\Repository\PointsRepository;

class AdRepository
{
    public function __construct(
        protected AdvertisingSettings $settings,
        protected PointsRepository $points,
        protected ConnectionInterface $db,
        protected AdvertisingNotifier $notifier,
    ) {
    }

    public function create(User $actor, array $attributes): Ad
    {
        if (! $this->settings->enabled()) {
            throw new ValidationException(['message' => '广告位暂未开放购买。']);
        }

        $slotKey = $this->normalizeSlotKey($attributes['slotKey'] ?? 'sidebar');
        $slotPosition = $this->normalizeSlotPosition($slotKey, $attributes['slotPosition'] ?? null);
        $durationPlanKey = $this->normalizeDurationPlan($attributes['durationPlan'] ?? $attributes['durationDays'] ?? '1_month');
        $durationPlan = $this->settings->durationPlan($durationPlanKey);
        $totalPrice = $this->totalPrice($slotKey, $durationPlanKey);

        // Do not reserve points while pending; approval rechecks atomically.
        $this->assertSufficientBalance($actor, $totalPrice);

        $ad = $this->db->transaction(function () use ($actor, $attributes, $slotKey, $slotPosition, $durationPlanKey, $durationPlan, $totalPrice) {
            $this->assertSlotAvailable($slotKey, $slotPosition);

            $ad = new Ad();
            $ad->user_id = (int) $actor->id;
            $ad->slot_key = $slotKey;
            $ad->slot_position = $slotPosition;
            $ad->title = $this->normalizeTitle($attributes['title'] ?? '');
            $ad->image_path = $this->normalizeImagePath($attributes['imagePath'] ?? '');
            $ad->target_url = $this->normalizeUrl($attributes['targetUrl'] ?? '');
            $ad->duration_plan = $durationPlanKey;
            $ad->duration_days = (int) $durationPlan['days'];
            // This legacy column now stores the monthly price.
            $ad->price_per_day = $this->settings->pricePerMonth($slotKey);
            $ad->total_price = $totalPrice;
            $ad->status = 'pending';
            $ad->is_visible = false;
            $ad->sort_order = $slotPosition;
            $ad->save();

            return $ad;
        });

        $ad = $ad->fresh('user');
        $this->notifier->notifyPendingReview($ad, $actor);

        return $ad;
    }

    public function renew(User $actor, Ad $ad): Ad
    {
        if (! $this->settings->enabled()) {
            throw new ValidationException(['message' => '广告位暂未开放购买。']);
        }

        if (! $this->settings->renewalEnabled()) {
            throw new ValidationException(['message' => '广告续费功能暂未开放。']);
        }

        $updated = $this->db->transaction(function () use ($actor, $ad) {
            $renewed = Ad::query()->whereKey($ad->id)->lockForUpdate()->firstOrFail();

            if ((int) $renewed->user_id !== (int) $actor->id) {
                throw new ValidationException(['message' => '你不能续费其他用户的广告。']);
            }

            if ($renewed->status !== 'approved') {
                throw new ValidationException(['status' => '只有已通过的广告可以续费。']);
            }

            if (! $this->settings->slotEnabled((string) $renewed->slot_key)) {
                throw new ValidationException(['slotKey' => '该广告位当前未开放。']);
            }

            $durationPlanKey = $this->normalizeDurationPlan($renewed->duration_plan ?: $renewed->duration_days);
            $durationPlan = $this->settings->durationPlan($durationPlanKey);
            $renewalPrice = $this->totalPrice((string) $renewed->slot_key, $durationPlanKey);

            if ($renewalPrice > 0) {
                try {
                    $this->points->deduct(
                        $actor,
                        $renewalPrice,
                        'advertising.renewal',
                        'advertising_ad',
                        (int) $renewed->id
                    );
                } catch (\DomainException) {
                    throw new ValidationException(['points' => '用户积分余额不足，无法续费该广告。']);
                }
            }

            $now = Carbon::now('Asia/Shanghai');
            $base = $renewed->ends_at && $renewed->ends_at->isFuture()
                ? $renewed->ends_at->copy()
                : $now;

            $renewed->ends_at = $base->addDays((int) $durationPlan['days']);
            $renewed->is_visible = true;
            $renewed->save();

            return $renewed->fresh('user');
        });

        return $updated;
    }

    public function updateByAdmin(User $actor, Ad $ad, array $attributes): Ad
    {
        $statusChanged = false;
        $updated = $this->db->transaction(function () use ($actor, $ad, $attributes, &$statusChanged) {
            $previousStatus = (string) $ad->status;
            $pricingChanged = false;

            if (array_key_exists('title', $attributes)) {
                $ad->title = $this->normalizeTitle($attributes['title']);
            }
            if (array_key_exists('targetUrl', $attributes)) {
                $ad->target_url = $this->normalizeUrl($attributes['targetUrl']);
            }
            if (array_key_exists('imagePath', $attributes)) {
                $ad->image_path = $this->normalizeImagePath($attributes['imagePath']);
            }
            if (array_key_exists('slotKey', $attributes)) {
                $ad->slot_key = $this->normalizeSlotKey($attributes['slotKey']);
                $pricingChanged = true;
            }
            if (array_key_exists('slotPosition', $attributes)) {
                $ad->slot_position = $this->normalizeSlotPosition($ad->slot_key, $attributes['slotPosition']);
                $ad->sort_order = (int) $ad->slot_position;
            }
            if (array_key_exists('durationPlan', $attributes) || array_key_exists('durationDays', $attributes)) {
                $planKey = $this->normalizeDurationPlan($attributes['durationPlan'] ?? $attributes['durationDays']);
                $plan = $this->settings->durationPlan($planKey);
                $ad->duration_plan = $planKey;
                $ad->duration_days = (int) $plan['days'];
                $pricingChanged = true;
            }
            if (array_key_exists('pricePerMonth', $attributes) || array_key_exists('pricePerDay', $attributes)) {
                $ad->price_per_day = max(0, (int) ($attributes['pricePerMonth'] ?? $attributes['pricePerDay']));
                $pricingChanged = true;
            }
            if (array_key_exists('sortOrder', $attributes)) {
                $ad->sort_order = (int) $attributes['sortOrder'];
            }
            if (array_key_exists('reviewNote', $attributes)) {
                $ad->review_note = $this->nullableText($attributes['reviewNote']);
            }

            if ($pricingChanged) {
                $ad->total_price = max(0, (int) $ad->price_per_day * $this->monthsForAd($ad));
            }

            if (array_key_exists('status', $attributes)) {
                $status = (string) $attributes['status'];
                $this->applyStatus($ad, $status, $previousStatus);
                $statusChanged = $status !== $previousStatus;
            } elseif (array_key_exists('isVisible', $attributes)) {
                $ad->is_visible = (bool) $attributes['isVisible'] && $ad->status === 'approved';
            }

            if (array_key_exists('status', $attributes)) {
                $ad->reviewed_by = (int) $actor->id;
                $ad->reviewed_at = Carbon::now('Asia/Shanghai');
            }
            $ad->save();

            return $ad->fresh('user');
        });

        if ($statusChanged && in_array($updated->status, ['approved', 'rejected'], true)) {
            $this->notifier->notifyReviewed($updated, $actor);
        }

        return $updated;
    }

    public function expireDueAds(int $limit = 500): int
    {
        $ads = Ad::query()
            ->where('status', 'approved')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', Carbon::now('Asia/Shanghai'))
            ->limit(max(1, $limit))
            ->get();

        foreach ($ads as $ad) {
            $ad->status = 'expired';
            $ad->is_visible = false;
            $ad->save();
        }

        return $ads->count();
    }

    public function totalPrice(string $slotKey, string $durationPlanKey): int
    {
        $slotKey = $this->normalizeSlotKey($slotKey);
        $plan = $this->settings->durationPlan($this->normalizeDurationPlan($durationPlanKey));

        return $this->settings->pricePerMonth($slotKey) * (int) $plan['months'];
    }

    public function balance(User $user): int
    {
        return (int) $this->points->getOrCreate($user)->balance;
    }

    protected function applyStatus(Ad $ad, string $status, string $previousStatus): void
    {
        if (! in_array($status, ['pending', 'approved', 'rejected', 'expired', 'hidden'], true)) {
            throw new ValidationException(['status' => '广告状态无效。']);
        }

        $ad->status = $status;

        if ($status === 'approved') {
            if ($ad->slot_position === null) {
                $ad->slot_position = $this->firstAvailableSlotPosition($ad->slot_key, (int) $ad->id);
                $ad->sort_order = (int) $ad->slot_position;
            }
            $this->assertSlotAvailable($ad->slot_key, $ad->slot_position, (int) $ad->id);
            if ($previousStatus !== 'approved' && ! $ad->point_transaction_id && $ad->total_price > 0) {
                try {
                    $tx = $this->points->deduct(
                        $ad->user,
                        (int) $ad->total_price,
                        'advertising.purchase',
                        'advertising_ad',
                        (int) $ad->id
                    );
                } catch (\DomainException) {
                    throw new ValidationException(['points' => '用户积分余额不足，无法通过该广告。']);
                }

                $ad->point_transaction_id = (int) $tx->id;
            }

            $start = $ad->starts_at ?: Carbon::now('Asia/Shanghai');
            $ad->starts_at = $start;
            if (! $ad->ends_at || $ad->ends_at->isPast()) {
                $ad->ends_at = $start->copy()->addDays($this->durationDaysForAd($ad));
            }
            $ad->is_visible = true;

            return;
        }

        $ad->is_visible = false;
    }

    protected function assertSlotAvailable(string $slotKey, ?int $position, ?int $exceptId = null): void
    {
        if (! $this->settings->slotEnabled($slotKey)) {
            throw new ValidationException(['slotKey' => '该广告位当前未开放。']);
        }

        if ($position === null) {
            throw new ValidationException(['slotPosition' => '请选择广告位置。']);
        }

        $query = Ad::query()
            ->where('slot_key', $slotKey)
            ->where('slot_position', $position)
            ->whereIn('status', ['pending', 'approved'])
            ->lockForUpdate();

        if ($exceptId !== null) {
            $query->where('id', '<>', $exceptId);
        }

        if ($query->exists()) {
            throw new ValidationException(['slotPosition' => '该广告位置已被占用，请选择其他位置。']);
        }
    }

    protected function normalizeSlotPosition(string $slotKey, mixed $value): int
    {
        if ($value === null || $value === '') {
            throw new ValidationException(['slotPosition' => '请选择广告位置。']);
        }

        $position = (int) $value;
        if ($position < 1 || $position > $this->settings->slotCount($slotKey)) {
            throw new ValidationException(['slotPosition' => '广告位置无效。']);
        }

        return $position;
    }

    protected function firstAvailableSlotPosition(string $slotKey, ?int $exceptId = null): int
    {
        for ($position = 1; $position <= $this->settings->slotCount($slotKey); $position++) {
            try {
                $this->assertSlotAvailable($slotKey, $position, $exceptId);

                return $position;
            } catch (ValidationException) {
                continue;
            }
        }

        throw new ValidationException(['slotPosition' => '该广告位没有可用位置。']);
    }

    protected function assertSufficientBalance(User $user, int $required): void
    {
        if ($required > 0 && $this->balance($user) < $required) {
            throw new ValidationException(['points' => '积分不足，需要赚取积分后再提交广告申请。']);
        }
    }

    protected function normalizeSlotKey(mixed $value): string
    {
        $slotKey = (string) $value;

        if (! array_key_exists($slotKey, AdvertisingSettings::SLOT_LABELS)) {
            throw new ValidationException(['slotKey' => '广告位无效。']);
        }

        return $slotKey;
    }

    protected function normalizeDurationPlan(mixed $value): string
    {
        $key = (string) $value;

        if (array_key_exists($key, AdvertisingSettings::DURATION_PLANS)) {
            return $key;
        }

        $days = (int) $value;
        foreach (AdvertisingSettings::DURATION_PLANS as $planKey => $plan) {
            if ($days === (int) $plan['days']) {
                return $planKey;
            }
        }

        throw new ValidationException(['durationPlan' => '请选择有效的展示时长。']);
    }

    protected function monthsForDays(int $days): int
    {
        foreach (AdvertisingSettings::DURATION_PLANS as $plan) {
            if ($days === (int) $plan['days']) {
                return (int) $plan['months'];
            }
        }

        return max(1, (int) ceil($days / 30));
    }

    protected function monthsForAd(Ad $ad): int
    {
        if ($ad->duration_plan && array_key_exists($ad->duration_plan, AdvertisingSettings::DURATION_PLANS)) {
            return (int) AdvertisingSettings::DURATION_PLANS[$ad->duration_plan]['months'];
        }

        return $this->monthsForDays((int) $ad->duration_days);
    }

    protected function durationDaysForAd(Ad $ad): int
    {
        if ($ad->duration_plan && array_key_exists($ad->duration_plan, AdvertisingSettings::DURATION_PLANS)) {
            return (int) AdvertisingSettings::DURATION_PLANS[$ad->duration_plan]['days'];
        }

        return max(1, (int) $ad->duration_days);
    }

    protected function normalizeTitle(mixed $value): string
    {
        $title = trim((string) $value);

        if (mb_strlen($title) < 2 || mb_strlen($title) > 120) {
            throw new ValidationException(['title' => '广告标题需要为 2 到 120 个字符。']);
        }

        return $title;
    }

    protected function normalizeImagePath(mixed $value): string
    {
        $path = trim((string) $value);

        if ($path === '' || ! preg_match('~^/assets/lowseekai-advertising/[a-zA-Z0-9._-]+$~', $path)) {
            throw new ValidationException(['imagePath' => '请先上传有效的广告图片。']);
        }

        return $path;
    }

    protected function normalizeUrl(mixed $value): string
    {
        $url = trim((string) $value);

        if (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('~^https?://~i', $url)) {
            throw new ValidationException(['targetUrl' => '请填写有效的 HTTP 或 HTTPS 跳转链接。']);
        }

        return mb_substr($url, 0, 500);
    }

    protected function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? mb_substr($text, 0, 2000) : null;
    }
}
