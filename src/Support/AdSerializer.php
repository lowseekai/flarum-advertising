<?php

declare(strict_types=1);

namespace Lowseekai\Advertising\Support;

use Carbon\CarbonInterface;
use Lowseekai\Advertising\Model\Ad;

class AdSerializer
{
    public function __construct(protected AdvertisingSettings $settings)
    {
    }

    public function serialize(Ad $ad): array
    {
        $user = $ad->user;

        return [
            'id' => (int) $ad->id,
            'userId' => (int) $ad->user_id,
            'slotKey' => $this->settings->normalizeSlotKey((string) $ad->slot_key),
            'slotLabel' => AdvertisingSettings::SLOT_LABELS[$this->settings->normalizeSlotKey((string) $ad->slot_key)] ?? $ad->slot_key,
            'slotPosition' => $ad->slot_position !== null ? (int) $ad->slot_position : null,
            'isReservation' => (bool) $ad->is_reservation,
            'title' => (string) $ad->title,
            'imagePath' => (string) $ad->image_path,
            'imageUrl' => $this->imageUrl((string) $ad->image_path),
            'targetUrl' => (string) $ad->target_url,
            'durationDays' => (int) $ad->duration_days,
            'durationPlan' => $ad->duration_plan ?: $this->durationPlan((int) $ad->duration_days),
            'pricePerDay' => (int) $ad->price_per_day,
            'pricePerMonth' => (int) $ad->price_per_day,
            'renewalPrice' => $this->settings->pricePerMonth((string) $ad->slot_key) * $this->monthsForAd($ad),
            'durationLabel' => $this->durationLabel($ad->duration_plan ?: $this->durationPlan((int) $ad->duration_days)),
            'totalPrice' => (int) $ad->total_price,
            'refundTransactionId' => $ad->refund_transaction_id !== null ? (int) $ad->refund_transaction_id : null,
            'autoRenewEnabled' => (bool) $ad->auto_renew_enabled,
            'autoRenewStatus' => (string) ($ad->auto_renew_status ?: 'disabled'),
            'autoRenewPrice' => $ad->auto_renew_price !== null ? (int) $ad->auto_renew_price : null,
            'autoRenewLastAttemptAt' => $this->dateTime($ad->auto_renew_last_attempt_at),
            'autoRenewFailureReason' => $ad->auto_renew_failure_reason,
            'autoRenewDisabledAt' => $this->dateTime($ad->auto_renew_disabled_at),
            'nextAutoRenewAt' => $this->dateTime($ad->ends_at?->copy()->subDay()),
            'status' => (string) $ad->status,
            'isVisible' => (bool) $ad->is_visible,
            'sortOrder' => (int) $ad->sort_order,
            'startsAt' => $this->dateTime($ad->starts_at),
            'endsAt' => $this->dateTime($ad->ends_at),
            'reservedAt' => $this->dateTime($ad->reserved_at),
            'reservationEstimatedStartAt' => $this->dateTime($ad->reservation_estimated_start_at),
            'reservationWaitUntil' => $this->dateTime($ad->reservation_wait_until),
            'reservationDeferredCount' => (int) $ad->reservation_deferred_count,
            'reservationCancelledAt' => $this->dateTime($ad->reservation_cancelled_at),
            'reviewNote' => $ad->review_note,
            'createdAt' => $this->dateTime($ad->created_at),
            'updatedAt' => $this->dateTime($ad->updated_at),
            'user' => $user ? [
                'id' => (int) $user->id,
                'username' => (string) $user->username,
                'displayName' => (string) $user->display_name,
                'avatarUrl' => $user->avatar_url,
            ] : null,
        ];
    }

    public function imageUrl(string $path): string
    {
        if (preg_match('~^https?://~i', $path)) {
            return $path;
        }

        return '/'.ltrim($path, '/');
    }

    protected function durationPlan(int $days): string
    {
        foreach (AdvertisingSettings::DURATION_PLANS as $key => $plan) {
            if ((int) $plan['days'] === $days) {
                return $key;
            }
        }

        return '1_month';
    }

    protected function durationLabel(string $planKey): string
    {
        return AdvertisingSettings::DURATION_PLANS[$planKey]['label'] ?? $planKey;
    }

    protected function monthsForAd(Ad $ad): int
    {
        $planKey = $ad->duration_plan ?: $this->durationPlan((int) $ad->duration_days);

        return (int) (AdvertisingSettings::DURATION_PLANS[$planKey]['months'] ?? 1);
    }

    protected function dateTime(?CarbonInterface $date): ?string
    {
        return $date?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s');
    }
}
