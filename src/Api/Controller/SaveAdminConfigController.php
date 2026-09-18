<?php

declare(strict_types=1);

namespace Lowseekai\Advertising\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Lowseekai\Advertising\Support\AdvertisingSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class SaveAdminConfigController implements RequestHandlerInterface
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected AdvertisingSettings $advertising,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest() || ! $actor->hasPermission('lowseekai-advertising.manage')) {
            throw new PermissionDeniedException();
        }

        $attrs = (array) Arr::get($request->getParsedBody(), 'data.attributes', []);

        $values = [
            AdvertisingSettings::KEY_ENABLED => filter_var($attrs['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            AdvertisingSettings::KEY_SIDEBAR_ENABLED => filter_var($attrs['sidebarEnabled'] ?? true, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            AdvertisingSettings::KEY_TOP_ENABLED => filter_var($attrs['topEnabled'] ?? true, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            AdvertisingSettings::KEY_SIDEBAR_SLOTS => (string) max(1, min(50, (int) ($attrs['sidebarSlots'] ?? $this->advertising->slotCount('sidebar')))),
            AdvertisingSettings::KEY_TOP_SLOTS => (string) max(1, min(12, (int) ($attrs['topSlots'] ?? $this->advertising->slotCount('top')))),
            AdvertisingSettings::KEY_SIDEBAR_PRICE => (string) max(0, (int) ($attrs['sidebarPricePerMonth'] ?? $attrs['sidebarPricePerDay'] ?? $this->advertising->pricePerMonth('sidebar'))),
            AdvertisingSettings::KEY_TOP_PRICE => (string) max(0, (int) ($attrs['topPricePerMonth'] ?? $attrs['topPricePerDay'] ?? $this->advertising->pricePerMonth('top'))),
            AdvertisingSettings::KEY_MAX_IMAGE_SIZE => (string) max(128, min(20480, (int) ($attrs['maxImageSizeKb'] ?? $this->advertising->maxImageSizeKb()))),
            AdvertisingSettings::KEY_CURRENCY_NAME => mb_substr(trim((string) ($attrs['currencyName'] ?? $this->advertising->currencyName())), 0, 30) ?: '积分',
            AdvertisingSettings::KEY_CURRENCY_ICON => preg_match('/^[a-zA-Z0-9 _-]{1,80}$/', (string) ($attrs['currencyIcon'] ?? 'fas fa-coins')) ? (string) $attrs['currencyIcon'] : 'fas fa-coins',
        ];

        foreach ($values as $key => $value) {
            $this->settings->set($key, $value);
        }

        return new JsonResponse(['data' => [
            'enabled' => $values[AdvertisingSettings::KEY_ENABLED] === '1',
            'sidebarEnabled' => $values[AdvertisingSettings::KEY_SIDEBAR_ENABLED] === '1',
            'topEnabled' => $values[AdvertisingSettings::KEY_TOP_ENABLED] === '1',
            'sidebarSlots' => (int) $values[AdvertisingSettings::KEY_SIDEBAR_SLOTS],
            'topSlots' => (int) $values[AdvertisingSettings::KEY_TOP_SLOTS],
            'sidebarPricePerMonth' => (int) $values[AdvertisingSettings::KEY_SIDEBAR_PRICE],
            'topPricePerMonth' => (int) $values[AdvertisingSettings::KEY_TOP_PRICE],
            'durationPlans' => $this->advertising->durationPlans(),
            'maxImageSizeKb' => (int) $values[AdvertisingSettings::KEY_MAX_IMAGE_SIZE],
            'currencyName' => $values[AdvertisingSettings::KEY_CURRENCY_NAME],
            'currencyIcon' => $values[AdvertisingSettings::KEY_CURRENCY_ICON],
        ]]);
    }
}
