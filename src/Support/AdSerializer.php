<?php

declare(strict_types=1);

namespace Lowseekai\Advertising\Support;

use Lowseekai\Advertising\Model\Ad;

class AdSerializer
{
    public function serialize(Ad $ad): array
    {
        $user = $ad->user;

        return [
            'id' => (int) $ad->id,
            'userId' => (int) $ad->user_id,
            'slotKey' => (string) $ad->slot_key,
            'slotLabel' => AdvertisingSettings::SLOT_LABELS[$ad->slot_key] ?? $ad->slot_key,
            'title' => (string) $ad->title,
            'imagePath' => (string) $ad->image_path,
            'imageUrl' => $this->imageUrl((string) $ad->image_path),
            'targetUrl' => (string) $ad->target_url,
            'durationDays' => (int) $ad->duration_days,
            'pricePerDay' => (int) $ad->price_per_day,
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
}
