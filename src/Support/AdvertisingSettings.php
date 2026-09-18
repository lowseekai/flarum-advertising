<?php

declare(strict_types=1);

namespace Lowseekai\Advertising\Support;

use Flarum\Settings\SettingsRepositoryInterface;

class AdvertisingSettings
{
    public const KEY_ENABLED = 'lowseekai-advertising.enabled';
    public const KEY_SIDEBAR_ENABLED = 'lowseekai-advertising.sidebar_enabled';
    public const KEY_TOP_ENABLED = 'lowseekai-advertising.top_enabled';
    public const KEY_SIDEBAR_SLOTS = 'lowseekai-advertising.sidebar_slots';
    public const KEY_TOP_SLOTS = 'lowseekai-advertising.top_slots';
    public const KEY_SIDEBAR_PRICE = 'lowseekai-advertising.sidebar_price_per_month';
    public const KEY_TOP_PRICE = 'lowseekai-advertising.top_price_per_month';
    public const LEGACY_KEY_SIDEBAR_PRICE = 'lowseekai-advertising.sidebar_price_per_day';
    public const LEGACY_KEY_TOP_PRICE = 'lowseekai-advertising.top_price_per_day';
    public const KEY_MAX_IMAGE_SIZE = 'lowseekai-advertising.max_image_size_kb';
    public const KEY_CURRENCY_NAME = 'lowseekai-advertising.currency_name';
    public const KEY_CURRENCY_ICON = 'lowseekai-advertising.currency_icon';

    public const SLOT_LABELS = [
        'sidebar' => '侧栏广告位',
        'top' => '顶部广告位',
    ];

    public const DURATION_PLANS = [
        '1_month' => [
            'label' => '1 个月',
            'months' => 1,
            'days' => 30,
        ],
        '3_months' => [
            'label' => '3 个月',
            'months' => 3,
            'days' => 90,
        ],
        '6_months' => [
            'label' => '半年',
            'months' => 6,
            'days' => 180,
        ],
        '1_year' => [
            'label' => '一年',
            'months' => 12,
            'days' => 365,
        ],
    ];

    public function __construct(protected SettingsRepositoryInterface $settings)
    {
    }

    public function enabled(): bool
    {
        return filter_var($this->settings->get(self::KEY_ENABLED, true), FILTER_VALIDATE_BOOLEAN);
    }

    public function slotEnabled(string $slotKey): bool
    {
        return match ($slotKey) {
            'top' => filter_var($this->settings->get(self::KEY_TOP_ENABLED, true), FILTER_VALIDATE_BOOLEAN),
            default => filter_var($this->settings->get(self::KEY_SIDEBAR_ENABLED, true), FILTER_VALIDATE_BOOLEAN),
        };
    }

    public function slotCount(string $slotKey): int
    {
        return match ($slotKey) {
            'top' => max(1, min(12, (int) $this->settings->get(self::KEY_TOP_SLOTS, 3))),
            default => max(1, min(50, (int) $this->settings->get(self::KEY_SIDEBAR_SLOTS, 10))),
        };
    }

    public function maxImageSizeKb(): int
    {
        return max(128, min(20480, (int) $this->settings->get(self::KEY_MAX_IMAGE_SIZE, 2048)));
    }

    public function pricePerMonth(string $slotKey): int
    {
        return match ($slotKey) {
            'top' => max(0, (int) $this->settingWithLegacyFallback(self::KEY_TOP_PRICE, self::LEGACY_KEY_TOP_PRICE, 30)),
            default => max(0, (int) $this->settingWithLegacyFallback(self::KEY_SIDEBAR_PRICE, self::LEGACY_KEY_SIDEBAR_PRICE, 20)),
        };
    }

    // Compatibility alias for older request payloads and the historical DB column name.
    public function pricePerDay(string $slotKey): int
    {
        return $this->pricePerMonth($slotKey);
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
            'enabled' => $this->slotEnabled($key),
            'capacity' => $this->slotCount($key),
            'pricePerMonth' => $this->pricePerMonth($key),
        ], array_keys(self::SLOT_LABELS), array_values(self::SLOT_LABELS));
    }

    public function durationPlans(): array
    {
        return array_map(fn (string $key, array $plan) => [
            'key' => $key,
            'label' => $plan['label'],
            'months' => $plan['months'],
            'days' => $plan['days'],
        ], array_keys(self::DURATION_PLANS), array_values(self::DURATION_PLANS));
    }

    public function durationPlan(string $key): array
    {
        return self::DURATION_PLANS[$key] ?? self::DURATION_PLANS['1_month'];
    }

    protected function settingWithLegacyFallback(string $key, string $legacyKey, mixed $default): mixed
    {
        $value = $this->settings->get($key);

        return $value !== null ? $value : $this->settings->get($legacyKey, $default);
    }
}
