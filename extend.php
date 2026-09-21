<?php

declare(strict_types=1);

namespace Lowseekai\Advertising;

use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Illuminate\Console\Scheduling\Event;
use Lowseekai\Advertising\Api\Controller\AdminListAdsController;
use Lowseekai\Advertising\Api\Controller\CancelAdController;
use Lowseekai\Advertising\Api\Controller\CreateAdController;
use Lowseekai\Advertising\Api\Controller\GetAdminConfigController;
use Lowseekai\Advertising\Api\Controller\ListMyAdsController;
use Lowseekai\Advertising\Api\Controller\ListPublicAdsController;
use Lowseekai\Advertising\Api\Controller\ListSlotAvailabilityController;
use Lowseekai\Advertising\Api\Controller\AutoRenewController;
use Lowseekai\Advertising\Api\Controller\RenewAdController;
use Lowseekai\Advertising\Api\Controller\SaveAdminConfigController;
use Lowseekai\Advertising\Api\Controller\UpdateAdminAdController;
use Lowseekai\Advertising\Api\Controller\UploadImageController;
use Lowseekai\Advertising\Console\ExpireAdsCommand;
use Lowseekai\Advertising\Console\AutoRenewAdsCommand;
use Lowseekai\Advertising\Notification\AdPendingReviewBlueprint;
use Lowseekai\Advertising\Notification\AdReviewedBlueprint;
use Lowseekai\Advertising\Notification\AdAutoRenewalBlueprint;
use Lowseekai\Advertising\Support\AdvertisingSettings;
use Ramon\PointSystem\Repository\PointsRepository;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less')
        ->route('/advertising', 'lowseekai-advertising.index'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\View())
        ->namespace('lowseekai-advertising', __DIR__.'/views'),

    (new Extend\Notification())
        ->type(AdPendingReviewBlueprint::class, ['alert', 'email'])
        ->type(AdReviewedBlueprint::class, ['alert', 'email'])
        ->type(AdAutoRenewalBlueprint::class, ['alert', 'email']),

    (new Extend\Routes('api'))
        ->get('/advertising/public/ads', 'lowseekai-advertising.public.ads', ListPublicAdsController::class)
        ->get('/advertising/slots', 'lowseekai-advertising.slots', ListSlotAvailabilityController::class)
        ->get('/advertising/me/ads', 'lowseekai-advertising.me.ads', ListMyAdsController::class)
        ->post('/advertising/upload-image', 'lowseekai-advertising.upload-image', UploadImageController::class)
        ->post('/advertising/ads', 'lowseekai-advertising.ads.create', CreateAdController::class)
        ->post('/advertising/ads/{id:[0-9]+}/cancel', 'lowseekai-advertising.ads.cancel', CancelAdController::class)
        ->post('/advertising/ads/{id:[0-9]+}/renew', 'lowseekai-advertising.ads.renew', RenewAdController::class)
        ->post('/advertising/ads/{id:[0-9]+}/auto-renew', 'lowseekai-advertising.ads.auto-renew', AutoRenewController::class)
        ->get('/advertising/admin/ads', 'lowseekai-advertising.admin.ads', AdminListAdsController::class)
        ->patch('/advertising/admin/ads/{id:[0-9]+}', 'lowseekai-advertising.admin.ads.update', UpdateAdminAdController::class)
        ->post('/advertising/admin/ads/{id:[0-9]+}/update', 'lowseekai-advertising.admin.ads.update.post', UpdateAdminAdController::class)
        ->get('/advertising/admin/config', 'lowseekai-advertising.admin.config', GetAdminConfigController::class)
        ->post('/advertising/admin/config', 'lowseekai-advertising.admin.config.save', SaveAdminConfigController::class),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('lowseekaiAdvertisingCanSubmit')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('lowseekai-advertising.submit')),
            Schema\Boolean::make('lowseekaiAdvertisingCanManage')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('lowseekai-advertising.manage')),
            Schema\Boolean::make('lowseekaiAdvertisingEnabled')
                ->get(fn () => resolve(AdvertisingSettings::class)->enabled()),
            Schema\Boolean::make('lowseekaiAdvertisingSidebarEnabled')
                ->get(fn () => resolve(AdvertisingSettings::class)->slotEnabled('sidebar')),
            Schema\Boolean::make('lowseekaiAdvertisingLeftSidebarEnabled')
                ->get(fn () => resolve(AdvertisingSettings::class)->slotEnabled('left_sidebar')),
            Schema\Boolean::make('lowseekaiAdvertisingRightSidebarEnabled')
                ->get(fn () => resolve(AdvertisingSettings::class)->slotEnabled('right_sidebar')),
            Schema\Boolean::make('lowseekaiAdvertisingTopEnabled')
                ->get(fn () => resolve(AdvertisingSettings::class)->slotEnabled('top')),
            Schema\Integer::make('lowseekaiAdvertisingSidebarSlots')
                ->get(fn () => resolve(AdvertisingSettings::class)->slotCount('sidebar')),
            Schema\Integer::make('lowseekaiAdvertisingLeftSidebarSlots')
                ->get(fn () => resolve(AdvertisingSettings::class)->slotCount('left_sidebar')),
            Schema\Integer::make('lowseekaiAdvertisingRightSidebarSlots')
                ->get(fn () => resolve(AdvertisingSettings::class)->slotCount('right_sidebar')),
            Schema\Integer::make('lowseekaiAdvertisingLeftSidebarDisplaySlots')
                ->get(fn () => resolve(AdvertisingSettings::class)->displaySlotCount('left_sidebar')),
            Schema\Integer::make('lowseekaiAdvertisingRightSidebarDisplaySlots')
                ->get(fn () => resolve(AdvertisingSettings::class)->displaySlotCount('right_sidebar')),
            Schema\Integer::make('lowseekaiAdvertisingTopSlots')
                ->get(fn () => resolve(AdvertisingSettings::class)->slotCount('top')),
            Schema\Str::make('lowseekaiAdvertisingCurrencyName')
                ->get(fn () => resolve(AdvertisingSettings::class)->currencyName()),
            Schema\Str::make('lowseekaiAdvertisingCurrencyIcon')
                ->get(fn () => resolve(AdvertisingSettings::class)->currencyIcon()),
            Schema\Integer::make('lowseekaiAdvertisingPointBalance')
                ->get(function ($forum, Context $context) {
                    if ($context->getActor()->isGuest()) {
                        return 0;
                    }

                    return (int) resolve(PointsRepository::class)->getOrCreate($context->getActor())->balance;
                }),
            Schema\Integer::make('lowseekaiAdvertisingSidebarPricePerMonth')
                ->get(fn () => resolve(AdvertisingSettings::class)->pricePerMonth('sidebar')),
            Schema\Integer::make('lowseekaiAdvertisingLeftSidebarPricePerMonth')
                ->get(fn () => resolve(AdvertisingSettings::class)->pricePerMonth('left_sidebar')),
            Schema\Integer::make('lowseekaiAdvertisingRightSidebarPricePerMonth')
                ->get(fn () => resolve(AdvertisingSettings::class)->pricePerMonth('right_sidebar')),
            Schema\Integer::make('lowseekaiAdvertisingTopPricePerMonth')
                ->get(fn () => resolve(AdvertisingSettings::class)->pricePerMonth('top')),
            Schema\Arr::make('lowseekaiAdvertisingDurationPlans')
                ->get(fn () => resolve(AdvertisingSettings::class)->durationPlans()),
            Schema\Boolean::make('lowseekaiAdvertisingRenewalEnabled')
                ->get(fn () => resolve(AdvertisingSettings::class)->renewalEnabled()),
            Schema\Boolean::make('lowseekaiAdvertisingAutoRenewalEnabled')
                ->get(fn () => resolve(AdvertisingSettings::class)->autoRenewalEnabled()),
            Schema\Boolean::make('lowseekaiAdvertisingReservationEnabled')
                ->get(fn () => resolve(AdvertisingSettings::class)->reservationEnabled()),
            Schema\Integer::make('lowseekaiAdvertisingReservationLeadDays')
                ->get(fn () => resolve(AdvertisingSettings::class)->reservationLeadDays()),
            Schema\Integer::make('lowseekaiAdvertisingReservationWaitDays')
                ->get(fn () => resolve(AdvertisingSettings::class)->reservationWaitDays()),
        ]),

    (new Extend\Console())
        ->command(ExpireAdsCommand::class)
        ->schedule(ExpireAdsCommand::class, function (Event $event) {
            $event->hourly();
        }),

    (new Extend\Console())
        ->command(AutoRenewAdsCommand::class)
        ->schedule(AutoRenewAdsCommand::class, function (Event $event) {
            $event->hourly();
        }),

    (new Extend\Settings())
        ->default('lowseekai-advertising.enabled', true)
        ->default('lowseekai-advertising.sidebar_enabled', true)
        ->default('lowseekai-advertising.left_sidebar_enabled', false)
        ->default('lowseekai-advertising.top_enabled', true)
        ->default('lowseekai-advertising.sidebar_slots', 10)
        ->default('lowseekai-advertising.left_sidebar_slots', 10)
        ->default('lowseekai-advertising.left_sidebar_display_slots', 3)
        ->default('lowseekai-advertising.right_sidebar_display_slots', 3)
        ->default('lowseekai-advertising.top_slots', 4)
        ->default('lowseekai-advertising.sidebar_price_per_month', 20)
        ->default('lowseekai-advertising.left_sidebar_price_per_month', 20)
        ->default('lowseekai-advertising.top_price_per_month', 30)
        ->default('lowseekai-advertising.max_image_size_kb', 2048)
        ->default('lowseekai-advertising.currency_name', '积分')
        ->default('lowseekai-advertising.currency_icon', 'fas fa-coins')
        ->default('lowseekai-advertising.renewal_enabled', true)
        ->default('lowseekai-advertising.auto_renewal_enabled', false)
        ->default('lowseekai-advertising.reservation_enabled', false)
        ->default('lowseekai-advertising.reservation_lead_days', 15)
        ->default('lowseekai-advertising.reservation_wait_days', 30)
        ->default('lowseekai-advertising.reservation_max_queue', 10)
        ->default('lowseekai-advertising.reservation_top_enabled', true)
        ->default('lowseekai-advertising.reservation_left_sidebar_enabled', true)
        ->default('lowseekai-advertising.reservation_right_sidebar_enabled', true)
        ->default('lowseekai-advertising.auto_group_enabled', false),

    (new Extend\Policy())
        ->globalPolicy(Access\AdvertisingPolicy::class),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-audit', fn () => [
            (new \Flarum\Audit\Extend\Audit())
                ->group('lowseekai-advertising')
                ->using(new AuditIntegration()),
        ]),
];
