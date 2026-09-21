<?php

declare(strict_types=1);

namespace Lowseekai\Advertising\Support;

use Carbon\Carbon;
use Flarum\Foundation\ValidationException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Lowseekai\Advertising\Model\Ad;
use Lowseekai\Advertising\Model\AdRenewal;
use Ramon\PointSystem\Repository\PointsRepository;

class AdRepository
{
    public function __construct(
        protected AdvertisingSettings $settings,
        protected PointsRepository $points,
        protected ConnectionInterface $db,
        protected AdvertisingNotifier $notifier,
        protected AdvertisingAutoGroupManager $autoGroups,
    ) {
    }

    public function create(User $actor, array $attributes): Ad
    {
        if (! $this->settings->enabled()) {
            throw new ValidationException(['message' => '广告位暂未开放购买。']);
        }

        $slotKey = $this->normalizeSlotKey($attributes['slotKey'] ?? 'sidebar');
        $durationPlanKey = $this->normalizeDurationPlan($attributes['durationPlan'] ?? $attributes['durationDays'] ?? '1_month');
        $durationPlan = $this->settings->durationPlan($durationPlanKey);
        $totalPrice = $this->totalPrice($slotKey, $durationPlanKey);
        $autoRenewEnabled = $this->settings->autoRenewalEnabled()
            && filter_var($attributes['autoRenewEnabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Do not reserve points while pending; approval rechecks atomically.
        $this->assertSufficientBalance($actor, $totalPrice);

        $ad = $this->db->transaction(function () use ($actor, $attributes, $slotKey, $durationPlanKey, $durationPlan, $totalPrice, $autoRenewEnabled) {
            $isReservation = false;
            $slotPosition = null;

            try {
                $slotPosition = $this->firstAvailableSlotPosition($slotKey);
            } catch (ValidationException $exception) {
                if (! $this->canReserveSlot($slotKey, $actor)) {
                    throw $exception;
                }

                $isReservation = true;
            }

            $ad = new Ad();
            $ad->user_id = (int) $actor->id;
            $ad->slot_key = $slotKey;
            $ad->slot_position = $slotPosition;
            $ad->is_reservation = $isReservation;
            $ad->title = $this->normalizeTitle($attributes['title'] ?? '');
            $ad->image_path = $this->normalizeImagePath($attributes['imagePath'] ?? '');
            $ad->target_url = $this->normalizeUrl($attributes['targetUrl'] ?? '');
            $ad->duration_plan = $durationPlanKey;
            $ad->duration_days = (int) $durationPlan['days'];
            // This legacy column now stores the monthly price.
            $ad->price_per_day = $this->settings->pricePerMonth($slotKey);
            $ad->total_price = $totalPrice;
            $ad->auto_renew_enabled = $autoRenewEnabled;
            $ad->auto_renew_price = $autoRenewEnabled ? $totalPrice : null;
            $ad->auto_renew_status = $autoRenewEnabled ? 'active' : 'disabled';
            $ad->status = 'pending';
            $ad->is_visible = false;
            $ad->sort_order = $slotPosition ?: 0;
            if ($isReservation) {
                $ad->reservation_estimated_start_at = $this->earliestReservableReleaseAt($slotKey);
                $ad->reservation_wait_until = Carbon::now('Asia/Shanghai')->addDays($this->settings->reservationWaitDays());
            }
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
            if ($renewed->auto_renew_enabled) {
                $renewed->auto_renew_price = $renewalPrice;
                $renewed->auto_renew_status = 'active';
                $renewed->auto_renew_failure_reason = null;
                $renewed->auto_renew_disabled_at = null;
            }
            $renewed->save();

            return $renewed->fresh('user');
        });

        $this->autoGroups->sync($updated);
        $this->refreshReservationEstimates((string) $updated->slot_key);

        return $updated;
    }

    public function setAutoRenew(User $actor, Ad $ad, bool $enabled): Ad
    {
        if ($enabled && ! $this->settings->autoRenewalEnabled()) {
            throw new ValidationException(['message' => '自动续费功能暂未开放。']);
        }

        $updated = $this->db->transaction(function () use ($actor, $ad, $enabled) {
            $updated = Ad::query()->whereKey($ad->id)->lockForUpdate()->firstOrFail();

            if ((int) $updated->user_id !== (int) $actor->id) {
                throw new ValidationException(['message' => '你不能修改其他用户广告的自动续费设置。']);
            }

            if ($updated->status !== 'approved' || ! $updated->is_visible) {
                throw new ValidationException(['status' => '只有已通过的广告可以设置自动续费。']);
            }

            if ($enabled && ! $this->settings->slotEnabled((string) $updated->slot_key)) {
                throw new ValidationException(['slotKey' => '该广告位当前未开放。']);
            }

            if ($enabled) {
                $durationPlanKey = $this->normalizeDurationPlan($updated->duration_plan ?: $updated->duration_days);
                $updated->auto_renew_enabled = true;
                $updated->auto_renew_price = $this->totalPrice((string) $updated->slot_key, $durationPlanKey);
                $updated->auto_renew_status = 'active';
                $updated->auto_renew_failure_reason = null;
                $updated->auto_renew_disabled_at = null;
            } else {
                $updated->auto_renew_enabled = false;
                $updated->auto_renew_status = 'disabled';
                $updated->auto_renew_disabled_at = Carbon::now('Asia/Shanghai');
            }

            $updated->save();

            return $updated->fresh('user');
        });

        if ($enabled) {
            $this->notifier->notifyAutoRenewal($updated, 'enabled');
        }
        $this->refreshReservationEstimates((string) $updated->slot_key);

        return $updated;
    }

    public function cancel(User $actor, Ad $ad): Ad
    {
        $updated = $this->db->transaction(function () use ($actor, $ad) {
            $updated = Ad::query()->with('user')->whereKey($ad->id)->lockForUpdate()->firstOrFail();

            if ((int) $updated->user_id !== (int) $actor->id) {
                throw new ValidationException(['message' => 'You cannot cancel another user reservation.']);
            }

            $this->cancelReservation($updated, 'cancelled');
            $updated->save();

            return $updated->fresh('user');
        });

        $this->activateQueuedReservations((string) $updated->slot_key);

        return $updated;
    }

    /**
     * Process each due campaign once. A unique cycle record is inserted before
     * charging points so retries or overlapping scheduler runs cannot double-charge.
     */
    public function autoRenewDueAds(int $limit = 500): array
    {
        if (! $this->settings->autoRenewalEnabled()) {
            return ['checked' => 0, 'renewed' => 0, 'failed' => 0, 'paused' => 0];
        }

        $now = Carbon::now('Asia/Shanghai');
        $ads = Ad::query()
            ->with('user')
            ->where('status', 'approved')
            ->where('is_visible', true)
            ->where('auto_renew_enabled', true)
            ->where('auto_renew_status', 'active')
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', $now)
            ->where('ends_at', '<=', $now->copy()->addDay())
            ->orderBy('ends_at')
            ->limit(max(1, $limit))
            ->get();

        $result = ['checked' => 0, 'renewed' => 0, 'failed' => 0, 'paused' => 0];

        foreach ($ads as $ad) {
            $result['checked']++;
            $outcome = $this->processAutoRenewal((int) $ad->id);

            if ($outcome['status'] === 'renewed') {
                $result['renewed']++;
            } elseif ($outcome['status'] === 'failed') {
                $result['failed']++;
            } elseif ($outcome['status'] === 'paused') {
                $result['paused']++;
            }

            if ($outcome['event'] && $outcome['ad']) {
                $this->notifier->notifyAutoRenewal(
                    $outcome['ad'],
                    $outcome['event'],
                    $outcome['reason'] ?? null,
                    $outcome['amount'] ?? 0
                );
            }

            if ($outcome['ad']) {
                $this->refreshReservationEstimates((string) $outcome['ad']->slot_key);
            }
        }

        return $result;
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
                $slotKey = $this->normalizeSlotKey($attributes['slotKey']);
                if ($slotKey !== $this->normalizeSlotKey((string) $ad->slot_key)) {
                    throw new ValidationException(['slotKey' => '广告不能跨区域移动。']);
                }
            }
            if (array_key_exists('slotPosition', $attributes)) {
                $this->moveWithinSlot($ad, $attributes['slotPosition']);
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
                if (! $ad->is_visible && $ad->auto_renew_enabled) {
                    $ad->auto_renew_enabled = false;
                    $ad->auto_renew_status = 'disabled';
                    $ad->auto_renew_disabled_at = Carbon::now('Asia/Shanghai');
                }
            }

            if (array_key_exists('status', $attributes)) {
                $ad->reviewed_by = (int) $actor->id;
                $ad->reviewed_at = Carbon::now('Asia/Shanghai');
            }
            $ad->save();

            return $ad->fresh('user');
        });

        if ($statusChanged && in_array($updated->status, ['approved', 'reserved', 'rejected', 'cancelled', 'expired'], true)) {
            $this->notifier->notifyReviewed($updated, $actor);
        }

        if ($statusChanged && $updated->status === 'approved' && $updated->auto_renew_enabled) {
            $this->notifier->notifyAutoRenewal($updated, 'enabled');
        }

        $this->autoGroups->sync($updated);

        if ($statusChanged && in_array($updated->status, ['hidden', 'cancelled', 'expired'], true)) {
            $this->activateQueuedReservations((string) $updated->slot_key);
        }
        $this->refreshReservationEstimates((string) $updated->slot_key);

        return $updated;
    }

    public function expireDueAds(int $limit = 500): int
    {
        $this->autoRenewDueAds($limit);

        $ads = Ad::query()
            ->where('status', 'approved')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', Carbon::now('Asia/Shanghai'))
            ->limit(max(1, $limit))
            ->get();

        foreach ($ads as $ad) {
            $ad->status = 'expired';
            $ad->is_visible = false;
            if ($ad->auto_renew_enabled) {
                $ad->auto_renew_enabled = false;
                $ad->auto_renew_status = 'disabled';
                $ad->auto_renew_disabled_at = Carbon::now('Asia/Shanghai');
            }
            $ad->save();
            $this->autoGroups->sync($ad);
            $this->activateQueuedReservations((string) $ad->slot_key);
        }

        $this->cancelExpiredReservations();

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
        if (! in_array($status, ['pending', 'approved', 'reserved', 'rejected', 'expired', 'hidden', 'cancelled'], true)) {
            throw new ValidationException(['status' => '广告状态无效。']);
        }

        if ($status === 'cancelled') {
            $this->cancelReservation($ad, 'cancelled');

            return;
        }

        if ($status === 'reserved') {
            $this->reserveApprovedAd($ad);

            return;
        }

        $ad->status = $status;

        if ($status === 'approved') {
            if ($ad->is_reservation && $previousStatus === 'pending' && ! $this->hasAvailableSlotPosition((string) $ad->slot_key, (int) $ad->id)) {
                $this->reserveApprovedAd($ad);

                return;
            }

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
            if ($ad->auto_renew_enabled && ! $ad->auto_renew_price) {
                $ad->auto_renew_price = $ad->total_price;
                $ad->auto_renew_status = 'active';
            }
            $ad->is_visible = true;

            return;
        }

        $ad->is_visible = false;
        if ($ad->auto_renew_enabled) {
            $ad->auto_renew_enabled = false;
            $ad->auto_renew_status = 'disabled';
            $ad->auto_renew_disabled_at = Carbon::now('Asia/Shanghai');
        }
    }

    protected function processAutoRenewal(int $adId): array
    {
        return $this->db->transaction(function () use ($adId) {
            $ad = Ad::query()
                ->with('user')
                ->whereKey($adId)
                ->lockForUpdate()
                ->first();

            if (! $ad || $ad->status !== 'approved' || ! $ad->is_visible || ! $ad->auto_renew_enabled || $ad->auto_renew_status !== 'active' || ! $ad->ends_at) {
                return ['status' => 'skipped', 'event' => null, 'ad' => null];
            }

            $now = Carbon::now('Asia/Shanghai');
            if ($ad->ends_at->lte($now) || $ad->ends_at->gt($now->copy()->addDay())) {
                return ['status' => 'skipped', 'event' => null, 'ad' => null];
            }

            $cycleKey = $this->renewalCycleKey($ad->ends_at);
            if (AdRenewal::query()->where('ad_id', $ad->id)->where('cycle_key', $cycleKey)->exists()) {
                return ['status' => 'skipped', 'event' => null, 'ad' => null];
            }

            $durationPlanKey = $this->normalizeDurationPlan($ad->duration_plan ?: $ad->duration_days);
            $durationPlan = $this->settings->durationPlan($durationPlanKey);
            $amount = $this->totalPrice((string) $ad->slot_key, $durationPlanKey);
            $attemptedAt = Carbon::now('Asia/Shanghai');
            $renewal = new AdRenewal([
                'ad_id' => (int) $ad->id,
                'user_id' => (int) $ad->user_id,
                'cycle_key' => $cycleKey,
                'amount' => $amount,
                'duration_days' => (int) $durationPlan['days'],
                'previous_ends_at' => $ad->ends_at,
                'attempted_at' => $attemptedAt,
            ]);
            $renewal->save();

            if ((int) $ad->auto_renew_price !== $amount) {
                $reason = '广告位价格已发生变化，请手动确认新的续费价格。';
                $renewal->status = 'price_changed';
                $renewal->failure_reason = $reason;
                $renewal->save();
                $ad->auto_renew_enabled = false;
                $ad->auto_renew_status = 'price_changed';
                $ad->auto_renew_failure_reason = $reason;
                $ad->auto_renew_disabled_at = $attemptedAt;
                $ad->auto_renew_last_attempt_at = $attemptedAt;
                $ad->save();

                return ['status' => 'paused', 'event' => 'price_changed', 'ad' => $ad->fresh('user'), 'reason' => $reason, 'amount' => $amount];
            }

            try {
                $transaction = $amount > 0
                    ? $this->points->deduct($ad->user, $amount, 'advertising.auto_renewal', 'advertising_renewal', (int) $renewal->id)
                    : null;
            } catch (\DomainException) {
                $reason = '积分余额不足，自动续费已停止。';
                $renewal->status = 'failed';
                $renewal->failure_reason = $reason;
                $renewal->save();
                $ad->auto_renew_enabled = false;
                $ad->auto_renew_status = 'failed';
                $ad->auto_renew_failure_reason = $reason;
                $ad->auto_renew_disabled_at = $attemptedAt;
                $ad->auto_renew_last_attempt_at = $attemptedAt;
                $ad->save();

                return ['status' => 'failed', 'event' => 'failed', 'ad' => $ad->fresh('user'), 'reason' => $reason, 'amount' => $amount];
            }

            $newEndsAt = $ad->ends_at->copy()->addDays((int) $durationPlan['days']);
            $ad->ends_at = $newEndsAt;
            $ad->auto_renew_last_attempt_at = $attemptedAt;
            $ad->auto_renew_failure_reason = null;
            $ad->is_visible = true;
            $ad->save();

            $renewal->status = 'succeeded';
            $renewal->point_transaction_id = $transaction?->id;
            $renewal->new_ends_at = $newEndsAt;
            $renewal->save();

            return ['status' => 'renewed', 'event' => 'succeeded', 'ad' => $ad->fresh('user'), 'amount' => $amount];
        });
    }

    protected function renewalCycleKey(Carbon $endsAt): string
    {
        return $endsAt->copy()->utc()->format('YmdHis');
    }

    public function activateQueuedReservations(?string $slotKey = null, int $limit = 50): int
    {
        $activated = 0;
        $slotKeys = $slotKey ? [$this->normalizeSlotKey($slotKey)] : array_keys(AdvertisingSettings::SLOT_LABELS);

        foreach ($slotKeys as $key) {
            while ($activated < $limit && $this->hasAvailableSlotPosition($key)) {
                $reservation = Ad::query()
                    ->with('user')
                    ->where('is_reservation', true)
                    ->where('status', 'reserved')
                    ->where('slot_key', $key)
                    ->orderBy('reviewed_at')
                    ->orderBy('id')
                    ->first();

                if (! $reservation) {
                    break;
                }

                $this->db->transaction(function () use ($reservation) {
                    $ad = Ad::query()->with('user')->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
                    if ($ad->status !== 'reserved') {
                        return;
                    }

                    $this->activateApprovedAd($ad);
                    $ad->save();
                    $this->autoGroups->sync($ad);
                });

                $activated++;
            }

            $this->refreshReservationEstimates($key);
        }

        return $activated;
    }

    protected function activateApprovedAd(Ad $ad): void
    {
        if ($ad->slot_position === null) {
            $ad->slot_position = $this->firstAvailableSlotPosition((string) $ad->slot_key, (int) $ad->id);
            $ad->sort_order = (int) $ad->slot_position;
        }

        $this->assertSlotAvailable((string) $ad->slot_key, $ad->slot_position, (int) $ad->id);
        $this->chargePurchase($ad);

        $start = Carbon::now('Asia/Shanghai');
        $ad->status = 'approved';
        $ad->starts_at = $start;
        $ad->ends_at = $start->copy()->addDays($this->durationDaysForAd($ad));
        $ad->is_visible = true;
        $ad->reserved_at = null;
        $ad->reservation_estimated_start_at = null;
        $ad->reservation_wait_until = null;

        if ($ad->auto_renew_enabled && ! $ad->auto_renew_price) {
            $ad->auto_renew_price = $ad->total_price;
            $ad->auto_renew_status = 'active';
        }
    }

    protected function reserveApprovedAd(Ad $ad): void
    {
        if (! $ad->is_reservation) {
            throw new ValidationException(['status' => 'Only reservation advertisements can enter the queue.']);
        }

        if (! $this->canReserveSlot((string) $ad->slot_key, $ad->user, (int) $ad->id)) {
            throw new ValidationException(['slotKey' => 'This advertising area cannot be reserved right now.']);
        }

        $this->chargePurchase($ad);

        $now = Carbon::now('Asia/Shanghai');
        $ad->status = 'reserved';
        $ad->slot_position = null;
        $ad->sort_order = 0;
        $ad->is_visible = false;
        $ad->reserved_at = $ad->reserved_at ?: $now;
        $ad->reservation_estimated_start_at = $this->earliestReservableReleaseAt((string) $ad->slot_key);
        $ad->reservation_wait_until = $ad->reservation_wait_until ?: $now->copy()->addDays($this->settings->reservationWaitDays());
    }

    protected function chargePurchase(Ad $ad): void
    {
        if ($ad->point_transaction_id || $ad->total_price <= 0) {
            return;
        }

        try {
            $tx = $this->points->deduct($ad->user, (int) $ad->total_price, 'advertising.purchase', 'advertising_ad', (int) $ad->id);
        } catch (\DomainException) {
            throw new ValidationException(['points' => 'The user does not have enough points to approve this advertisement.']);
        }

        $ad->point_transaction_id = (int) $tx->id;
    }

    protected function cancelReservation(Ad $ad, string $status): void
    {
        if (! $ad->is_reservation || ! in_array((string) $ad->status, ['pending', 'reserved'], true)) {
            throw new ValidationException(['status' => 'Only pending or queued reservations can be cancelled.']);
        }

        $ad->status = $status;
        $ad->slot_position = null;
        $ad->sort_order = 0;
        $ad->is_visible = false;
        $ad->reservation_cancelled_at = Carbon::now('Asia/Shanghai');

        if ($ad->auto_renew_enabled) {
            $ad->auto_renew_enabled = false;
            $ad->auto_renew_status = 'disabled';
            $ad->auto_renew_disabled_at = Carbon::now('Asia/Shanghai');
        }

        if ($ad->point_transaction_id && ! $ad->refund_transaction_id && $ad->total_price > 0) {
            $tx = $this->points->award($ad->user, (int) $ad->total_price, 'advertising.reservation_refund', 'advertising_ad', (int) $ad->id);
            $ad->refund_transaction_id = $tx?->id;
        }
    }

    protected function cancelExpiredReservations(): int
    {
        $reservations = Ad::query()
            ->where('is_reservation', true)
            ->where('status', 'reserved')
            ->whereNotNull('reservation_wait_until')
            ->where('reservation_wait_until', '<=', Carbon::now('Asia/Shanghai'))
            ->limit(200)
            ->get();

        foreach ($reservations as $reservation) {
            $this->db->transaction(function () use ($reservation) {
                $ad = Ad::query()->with('user')->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
                if ($ad->status !== 'reserved') {
                    return;
                }

                $this->cancelReservation($ad, 'expired');
                $ad->save();
            });
        }

        return $reservations->count();
    }

    public function canReserveSlot(string $slotKey, ?User $user = null, ?int $exceptId = null): bool
    {
        $slotKey = $this->normalizeSlotKey($slotKey);

        if (! $this->settings->slotReservationEnabled($slotKey) || $this->earliestReservableReleaseAt($slotKey) === null) {
            return false;
        }

        if ($this->reservationQueueCount($slotKey, $exceptId) >= $this->settings->reservationMaxQueue()) {
            return false;
        }

        if ($user && Ad::query()
            ->where('is_reservation', true)
            ->where('user_id', (int) $user->id)
            ->where('slot_key', $slotKey)
            ->whereIn('status', ['pending', 'reserved'])
            ->when($exceptId !== null, fn ($query) => $query->where('id', '<>', $exceptId))
            ->exists()) {
            return false;
        }

        return true;
    }

    public function earliestReservableReleaseAt(string $slotKey): ?Carbon
    {
        $slotKey = $this->normalizeSlotKey($slotKey);
        $now = Carbon::now('Asia/Shanghai');

        $date = Ad::query()
            ->where('slot_key', $slotKey)
            ->where('status', 'approved')
            ->where('is_visible', true)
            ->where('auto_renew_enabled', false)
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', $now)
            ->where('ends_at', '<=', $now->copy()->addDays($this->settings->reservationLeadDays()))
            ->orderBy('ends_at')
            ->value('ends_at');

        return $date ? Carbon::parse($date, 'Asia/Shanghai') : null;
    }

    public function reservationQueueCount(string $slotKey, ?int $exceptId = null): int
    {
        return (int) Ad::query()
            ->where('is_reservation', true)
            ->where('slot_key', $this->normalizeSlotKey($slotKey))
            ->whereIn('status', ['pending', 'reserved'])
            ->when($exceptId !== null, fn ($query) => $query->where('id', '<>', $exceptId))
            ->count();
    }

    protected function hasAvailableSlotPosition(string $slotKey, ?int $exceptId = null): bool
    {
        try {
            $this->firstAvailableSlotPosition($slotKey, $exceptId);

            return true;
        } catch (ValidationException) {
            return false;
        }
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

    protected function moveWithinSlot(Ad $ad, mixed $targetPosition): void
    {
        if ($ad->is_reservation) {
            throw new ValidationException(['slotPosition' => '预定中的广告不能手动设置具体位置。']);
        }

        if (! in_array((string) $ad->status, ['pending', 'approved'], true)) {
            throw new ValidationException(['slotPosition' => '只有待审核或展示中的广告可以调整展示位置。']);
        }

        $slotKey = $this->normalizeSlotKey((string) $ad->slot_key);
        $targetPosition = $this->normalizeSlotPosition($slotKey, $targetPosition);
        $currentPosition = $ad->slot_position !== null ? (int) $ad->slot_position : null;

        if ($currentPosition === $targetPosition) {
            $ad->slot_position = $targetPosition;
            $ad->sort_order = $targetPosition;

            return;
        }

        $occupyingAd = Ad::query()
            ->where('slot_key', $slotKey)
            ->where('slot_position', $targetPosition)
            ->whereIn('status', ['pending', 'approved'])
            ->where('id', '<>', (int) $ad->id)
            ->lockForUpdate()
            ->first();

        if ($occupyingAd && $currentPosition === null) {
            throw new ValidationException(['slotPosition' => '该广告没有当前位置，无法与目标位置交换。']);
        }

        if ($occupyingAd) {
            $occupyingAd->slot_position = $currentPosition;
            $occupyingAd->sort_order = $currentPosition;
            $occupyingAd->save();
        }

        $ad->slot_position = $targetPosition;
        $ad->sort_order = $targetPosition;
    }

    public function refreshReservationEstimates(?string $slotKey = null): int
    {
        $slotKeys = $slotKey ? [$this->normalizeSlotKey($slotKey)] : array_keys(AdvertisingSettings::SLOT_LABELS);
        $updated = 0;

        foreach ($slotKeys as $key) {
            $estimate = $this->earliestReservableReleaseAt($key);
            $updated += Ad::query()
                ->where('is_reservation', true)
                ->where('status', 'reserved')
                ->where('slot_key', $key)
                ->update([
                    'reservation_estimated_start_at' => $estimate,
                ]);
        }

        return $updated;
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
        $slotKey = $this->settings->normalizeSlotKey((string) $value);

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
