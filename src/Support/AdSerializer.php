<?php

declare(strict_types=1);

namespace Lowseekai\Advertising\Support;

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
            'slotKey' => (string) $ad->slot_key,
            'slotLabel' => AdvertisingSettings::SLOT_LABELS[$ad->slot_key] ?? $ad->slot_key,
            'slotPosition' => $ad->slot_position !== null ? (int) $ad->slot_position : null,
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
            'status' => (string) $ad->status,
            'isVisible' => (bool) $ad->is_visible,
            'sortOrder' => (int) $ad->sort_order,
            'startsAt' => $ad->starts_at?->toISOString(),
            'endsAt' => $ad->ends_at?->toISOString(),
            'reviewNote' => $ad->review_note,
            'createdAt' => $ad->created_at?->toISOString(),
            'updatedAt' => $ad->updated_at?->toISOString(),
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
}
