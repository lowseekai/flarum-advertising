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
    ) {
    }

    public function create(User $actor, array $attributes): Ad
    {
        if (! $this->settings->enabled()) {
            throw new ValidationException(['message' => '广告位暂未开放购买。']);
        }

        $slotKey = $this->normalizeSlotKey($attributes['slotKey'] ?? 'sidebar');
        $durationDays = $this->normalizeDuration($attributes['durationDays'] ?? 7);
        $pricePerDay = $this->settings->pricePerDay($slotKey);

        $ad = new Ad();
        $ad->user_id = (int) $actor->id;
        $ad->slot_key = $slotKey;
        $ad->title = $this->normalizeTitle($attributes['title'] ?? '');
        $ad->image_path = $this->normalizeImagePath($attributes['imagePath'] ?? '');
        $ad->target_url = $this->normalizeUrl($attributes['targetUrl'] ?? '');
        $ad->duration_days = $durationDays;
        $ad->price_per_day = $pricePerDay;
        $ad->total_price = $durationDays * $pricePerDay;
        $ad->status = 'pending';
        $ad->is_visible = false;
        $ad->sort_order = 0;
        $ad->save();

        return $ad->fresh('user');
    }

    public function updateByAdmin(User $actor, Ad $ad, array $attributes): Ad
    {
        return $this->db->transaction(function () use ($actor, $ad, $attributes) {
            $previousStatus = (string) $ad->status;

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
            }
            if (array_key_exists('durationDays', $attributes)) {
                $ad->duration_days = $this->normalizeDuration($attributes['durationDays']);
            }
            if (array_key_exists('pricePerDay', $attributes)) {
                $ad->price_per_day = max(0, (int) $attributes['pricePerDay']);
            }
            if (array_key_exists('sortOrder', $attributes)) {
                $ad->sort_order = (int) $attributes['sortOrder'];
            }
            if (array_key_exists('reviewNote', $attributes)) {
                $ad->review_note = $this->nullableText($attributes['reviewNote']);
            }

            $ad->total_price = max(0, (int) $ad->duration_days * (int) $ad->price_per_day);

            if (array_key_exists('status', $attributes)) {
                $this->applyStatus($ad, (string) $attributes['status'], $previousStatus);
            } elseif (array_key_exists('isVisible', $attributes)) {
                $ad->is_visible = (bool) $attributes['isVisible'] && $ad->status === 'approved';
            }

            $ad->reviewed_by = (int) $actor->id;
            $ad->reviewed_at = Carbon::now();
            $ad->save();

            return $ad->fresh('user');
        });
    }

    public function expireDueAds(int $limit = 500): int
    {
        $ads = Ad::query()
            ->where('status', 'approved')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', Carbon::now())
            ->limit(max(1, $limit))
            ->get();

        foreach ($ads as $ad) {
            $ad->status = 'expired';
            $ad->is_visible = false;
            $ad->save();
        }

        return $ads->count();
    }

    protected function applyStatus(Ad $ad, string $status, string $previousStatus): void
    {
        if (! in_array($status, ['pending', 'approved', 'rejected', 'expired'], true)) {
            throw new ValidationException(['status' => '广告状态无效。']);
        }

        $ad->status = $status;

        if ($status === 'approved') {
            if ($previousStatus !== 'approved' && ! $ad->point_transaction_id && $ad->total_price > 0) {
                try {
                    $tx = $this->points->deduct($ad->user, (int) $ad->total_price, 'advertising.purchase', 'advertising_ad', (int) $ad->id);
                } catch (\DomainException) {
                    throw new ValidationException(['message' => '用户积分余额不足，无法通过该广告。']);
                }

                $ad->point_transaction_id = (int) $tx->id;
            }

            $start = $ad->starts_at ?: Carbon::now();
            $ad->starts_at = $start;
            $ad->ends_at = $start->copy()->addDays(max(1, (int) $ad->duration_days));
            $ad->is_visible = true;

            return;
        }

        $ad->is_visible = false;
    }

    protected function normalizeSlotKey(mixed $value): string
    {
        $slotKey = (string) $value;

        if (! array_key_exists($slotKey, AdvertisingSettings::SLOT_LABELS)) {
            throw new ValidationException(['slotKey' => '广告位无效。']);
        }

        return $slotKey;
    }

    protected function normalizeDuration(mixed $value): int
    {
        $days = (int) $value;

        if ($days < $this->settings->minDurationDays() || $days > $this->settings->maxDurationDays()) {
            throw new ValidationException(['durationDays' => sprintf('购买天数必须在 %d 到 %d 天之间。', $this->settings->minDurationDays(), $this->settings->maxDurationDays())]);
        }

        return $days;
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
