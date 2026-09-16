<?php

declare(strict_types=1);

namespace Lowseekai\Advertising\Support;

use Flarum\Settings\SettingsRepositoryInterface;

class AdvertisingSettings
{
    public const KEY_ENABLED = 'lowseekai-advertising.enabled';
    public const KEY_SIDEBAR_PRICE = 'lowseekai-advertising.sidebar_price_per_day';
    public const KEY_TOP_PRICE = 'lowseekai-advertising.top_price_per_day';
    public const KEY_MIN_DAYS = 'lowseekai-advertising.min_duration_days';
    public const KEY_MAX_DAYS = 'lowseekai-advertising.max_duration_days';
    public const KEY_MAX_IMAGE_SIZE = 'lowseekai-advertising.max_image_size_kb';
    public const KEY_CURRENCY_NAME = 'lowseekai-advertising.currency_name';
    public const KEY_CURRENCY_ICON = 'lowseekai-advertising.currency_icon';

    public const SLOT_LABELS = [
        'sidebar' => '侧栏广告位',
        'top' => '顶部广告位',
    ];

    public function __construct(protected SettingsRepositoryInterface $settings)
    {
    }

    public function enabled(): bool
    {
        return filter_var($this->settings->get(self::KEY_ENABLED, true), FILTER_VALIDATE_BOOLEAN);
    }

    public function minDurationDays(): int
    {
        return max(1, min(365, (int) $this->settings->get(self::KEY_MIN_DAYS, 1)));
    }

    public function maxDurationDays(): int
    {
        return max($this->minDurationDays(), min(365, (int) $this->settings->get(self::KEY_MAX_DAYS, 30)));
    }

    public function maxImageSizeKb(): int
    {
        return max(128, min(20480, (int) $this->settings->get(self::KEY_MAX_IMAGE_SIZE, 2048)));
    }

    public function pricePerDay(string $slotKey): int
    {
        return match ($slotKey) {
            'top' => max(0, (int) $this->settings->get(self::KEY_TOP_PRICE, 30)),
            default => max(0, (int) $this->settings->get(self::KEY_SIDEBAR_PRICE, 20)),
        };
    }

    public function currencyName(): string
    {
        $value = trim((string) $this->settings->get(self::KEY_CURRENCY_NAME, '积分'));

        return $value !== '' ? $value : '积分';
    }

    public function currencyIcon(): string
    {
        $value = trim((string) $this->settings->get(self::KEY_CURRENCY_ICON, 'fas fa-coins'));

        return preg_match('/^[a-zA-Z0-9 _-]{1,80}$/', $value) ? $value : 'fas fa-coins';
    }

    public function slots(): array
    {
        return array_map(fn (string $key, string $label) => [
            'key' => $key,
            'label' => $label,
            'pricePerDay' => $this->pricePerDay($key),
        ], array_keys(self::SLOT_LABELS), array_values(self::SLOT_LABELS));
    }
}
