<?php

declare(strict_types=1);

namespace Lowseekai\Advertising\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Group\Group;
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
        protected \Lowseekai\Advertising\Support\AdvertisingAutoGroupManager $autoGroups,
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
            AdvertisingSettings::KEY_LEFT_SIDEBAR_ENABLED => filter_var($attrs['leftSidebarEnabled'] ?? false, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            AdvertisingSettings::KEY_RIGHT_SIDEBAR_ENABLED => filter_var($attrs['rightSidebarEnabled'] ?? ($attrs['sidebarEnabled'] ?? true), FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            AdvertisingSettings::KEY_TOP_ENABLED => filter_var($attrs['topEnabled'] ?? true, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            AdvertisingSettings::KEY_SIDEBAR_SLOTS => (string) max(1, min(50, (int) ($attrs['sidebarSlots'] ?? $this->advertising->slotCount('sidebar')))),
            AdvertisingSettings::KEY_LEFT_SIDEBAR_SLOTS => (string) max(1, min(50, (int) ($attrs['leftSidebarSlots'] ?? $this->advertising->slotCount('left_sidebar')))),
            AdvertisingSettings::KEY_RIGHT_SIDEBAR_SLOTS => (string) max(1, min(50, (int) ($attrs['rightSidebarSlots'] ?? $this->advertising->slotCount('right_sidebar')))),
            AdvertisingSettings::KEY_LEFT_SIDEBAR_DISPLAY_SLOTS => (string) max(1, min(50, (int) ($attrs['leftSidebarDisplaySlots'] ?? $this->advertising->displaySlotCount('left_sidebar')))),
            AdvertisingSettings::KEY_RIGHT_SIDEBAR_DISPLAY_SLOTS => (string) max(1, min(50, (int) ($attrs['rightSidebarDisplaySlots'] ?? $this->advertising->displaySlotCount('right_sidebar')))),
            AdvertisingSettings::KEY_TOP_SLOTS => (string) AdvertisingSettings::TOP_SLOT_COUNT,
            AdvertisingSettings::KEY_SIDEBAR_PRICE => (string) max(0, (int) ($attrs['sidebarPricePerMonth'] ?? $attrs['sidebarPricePerDay'] ?? $this->advertising->pricePerMonth('sidebar'))),
            AdvertisingSettings::KEY_LEFT_SIDEBAR_PRICE => (string) max(0, (int) ($attrs['leftSidebarPricePerMonth'] ?? $this->advertising->pricePerMonth('left_sidebar'))),
            AdvertisingSettings::KEY_RIGHT_SIDEBAR_PRICE => (string) max(0, (int) ($attrs['rightSidebarPricePerMonth'] ?? $this->advertising->pricePerMonth('right_sidebar'))),
            AdvertisingSettings::KEY_TOP_PRICE => (string) max(0, (int) ($attrs['topPricePerMonth'] ?? $attrs['topPricePerDay'] ?? $this->advertising->pricePerMonth('top'))),
            AdvertisingSettings::KEY_MAX_IMAGE_SIZE => (string) max(128, min(20480, (int) ($attrs['maxImageSizeKb'] ?? $this->advertising->maxImageSizeKb()))),
            AdvertisingSettings::KEY_CURRENCY_NAME => mb_substr(trim((string) ($attrs['currencyName'] ?? $this->advertising->currencyName())), 0, 30) ?: '积分',
            AdvertisingSettings::KEY_CURRENCY_ICON => preg_match('/^[a-zA-Z0-9 _-]{1,80}$/', (string) ($attrs['currencyIcon'] ?? 'fas fa-coins')) ? (string) $attrs['currencyIcon'] : 'fas fa-coins',
            AdvertisingSettings::KEY_RENEWAL_ENABLED => filter_var($attrs['renewalEnabled'] ?? true, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            AdvertisingSettings::KEY_AUTO_RENEWAL_ENABLED => filter_var($attrs['autoRenewalEnabled'] ?? false, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            AdvertisingSettings::KEY_AUTO_GROUP_ENABLED => filter_var($attrs['autoGroupEnabled'] ?? false, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
        ];

        $requestedGroupId = array_key_exists('autoGroupId', $attrs)
            ? ($attrs['autoGroupId'] === null || $attrs['autoGroupId'] === '' ? null : (int) $attrs['autoGroupId'])
            : $this->advertising->autoGroupId();

        if ($requestedGroupId !== null && (! Group::query()->whereKey($requestedGroupId)->where('id', '<>', Group::GUEST_ID)->exists())) {
            throw new \Flarum\Foundation\ValidationException(['autoGroupId' => '请选择有效的用户组。']);
        }

        $autoGroupChanged = filter_var($attrs['autoGroupEnabled'] ?? $this->advertising->autoGroupEnabled(), FILTER_VALIDATE_BOOLEAN)
            !== $this->advertising->autoGroupEnabled()
            || $requestedGroupId !== $this->advertising->autoGroupId();

        if ($autoGroupChanged && ! $actor->hasPermission('lowseekai-advertising.auto_group')) {
            throw new PermissionDeniedException();
        }

        $values[AdvertisingSettings::KEY_AUTO_GROUP_ID] = $requestedGroupId !== null ? (string) $requestedGroupId : null;

        foreach ($values as $key => $value) {
            $this->settings->set($key, $value);
        }

        $this->autoGroups->reconcile();

        return new JsonResponse(['data' => [
            'enabled' => $values[AdvertisingSettings::KEY_ENABLED] === '1',
            'sidebarEnabled' => $values[AdvertisingSettings::KEY_SIDEBAR_ENABLED] === '1',
            'leftSidebarEnabled' => $values[AdvertisingSettings::KEY_LEFT_SIDEBAR_ENABLED] === '1',
            'rightSidebarEnabled' => $values[AdvertisingSettings::KEY_RIGHT_SIDEBAR_ENABLED] === '1',
            'topEnabled' => $values[AdvertisingSettings::KEY_TOP_ENABLED] === '1',
            'sidebarSlots' => (int) $values[AdvertisingSettings::KEY_SIDEBAR_SLOTS],
            'leftSidebarSlots' => (int) $values[AdvertisingSettings::KEY_LEFT_SIDEBAR_SLOTS],
            'rightSidebarSlots' => (int) $values[AdvertisingSettings::KEY_RIGHT_SIDEBAR_SLOTS],
            'leftSidebarDisplaySlots' => (int) $values[AdvertisingSettings::KEY_LEFT_SIDEBAR_DISPLAY_SLOTS],
            'rightSidebarDisplaySlots' => (int) $values[AdvertisingSettings::KEY_RIGHT_SIDEBAR_DISPLAY_SLOTS],
            'topSlots' => (int) $values[AdvertisingSettings::KEY_TOP_SLOTS],
            'sidebarPricePerMonth' => (int) $values[AdvertisingSettings::KEY_SIDEBAR_PRICE],
            'leftSidebarPricePerMonth' => (int) $values[AdvertisingSettings::KEY_LEFT_SIDEBAR_PRICE],
            'rightSidebarPricePerMonth' => (int) $values[AdvertisingSettings::KEY_RIGHT_SIDEBAR_PRICE],
            'topPricePerMonth' => (int) $values[AdvertisingSettings::KEY_TOP_PRICE],
            'durationPlans' => $this->advertising->durationPlans(),
            'maxImageSizeKb' => (int) $values[AdvertisingSettings::KEY_MAX_IMAGE_SIZE],
            'currencyName' => $values[AdvertisingSettings::KEY_CURRENCY_NAME],
            'currencyIcon' => $values[AdvertisingSettings::KEY_CURRENCY_ICON],
            'renewalEnabled' => $values[AdvertisingSettings::KEY_RENEWAL_ENABLED] === '1',
            'autoRenewalEnabled' => $values[AdvertisingSettings::KEY_AUTO_RENEWAL_ENABLED] === '1',
            'autoGroupEnabled' => $values[AdvertisingSettings::KEY_AUTO_GROUP_ENABLED] === '1',
            'autoGroupId' => $requestedGroupId,
        ]]);
    }
}
