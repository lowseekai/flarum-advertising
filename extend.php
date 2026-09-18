<?php

declare(strict_types=1);

namespace Lowseekai\Advertising;

use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Illuminate\Console\Scheduling\Event;
use Lowseekai\Advertising\Api\Controller\AdminListAdsController;
use Lowseekai\Advertising\Api\Controller\CreateAdController;
use Lowseekai\Advertising\Api\Controller\GetAdminConfigController;
use Lowseekai\Advertising\Api\Controller\ListMyAdsController;
use Lowseekai\Advertising\Api\Controller\ListPublicAdsController;
use Lowseekai\Advertising\Api\Controller\ListSlotAvailabilityController;
use Lowseekai\Advertising\Api\Controller\RenewAdController;
use Lowseekai\Advertising\Api\Controller\SaveAdminConfigController;
use Lowseekai\Advertising\Api\Controller\UpdateAdminAdController;
use Lowseekai\Advertising\Api\Controller\UploadImageController;
use Lowseekai\Advertising\Console\ExpireAdsCommand;
use Lowseekai\Advertising\Notification\AdPendingReviewBlueprint;
use Lowseekai\Advertising\Notification\AdReviewedBlueprint;
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
        ->type(AdReviewedBlueprint::class, ['alert', 'email']),

    (new Extend\Routes('api'))
        ->get('/advertising/public/ads', 'lowseekai-advertising.public.ads', ListPublicAdsController::class)
        ->get('/advertising/slots', 'lowseekai-advertising.slots', ListSlotAvailabilityController::class)
        ->get('/advertising/me/ads', 'lowseekai-advertising.me.ads', ListMyAdsController::class)
        ->post('/advertising/upload-image', 'lowseekai-advertising.upload-image', UploadImageController::class)
        ->post('/advertising/ads', 'lowseekai-advertising.ads.create', CreateAdController::class)
        ->post('/advertising/ads/{id:[0-9]+}/renew', 'lowseekai-advertising.ads.renew', RenewAdController::class)
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
            Schema\Boolean::make('lowseekaiAdvertisingTopEnabled')
                ->get(fn () => resolve(AdvertisingSettings::class)->slotEnabled('top')),
            Schema\Integer::make('lowseekaiAdvertisingSidebarSlots')
                ->get(fn () => resolve(AdvertisingSettings::class)->slotCount('sidebar')),
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
            Schema\Integer::make('lowseekaiAdvertisingTopPricePerMonth')
                ->get(fn () => resolve(AdvertisingSettings::class)->pricePerMonth('top')),
            Schema\Arr::make('lowseekaiAdvertisingDurationPlans')
                ->get(fn () => resolve(AdvertisingSettings::class)->durationPlans()),
            Schema\Boolean::make('lowseekaiAdvertisingRenewalEnabled')
                ->get(fn () => resolve(AdvertisingSettings::class)->renewalEnabled()),
        ]),

    (new Extend\Console())
        ->command(ExpireAdsCommand::class)
        ->schedule(ExpireAdsCommand::class, function (Event $event) {
            $event->hourly();
        }),

    (new Extend\Settings())
        ->default('lowseekai-advertising.enabled', true)
        ->default('lowseekai-advertising.sidebar_enabled', true)
        ->default('lowseekai-advertising.top_enabled', true)
        ->default('lowseekai-advertising.sidebar_slots', 10)
        ->default('lowseekai-advertising.top_slots', 4)
        ->default('lowseekai-advertising.sidebar_price_per_month', 20)
        ->default('lowseekai-advertising.top_price_per_month', 30)
        ->default('lowseekai-advertising.max_image_size_kb', 2048)
        ->default('lowseekai-advertising.currency_name', '积分')
        ->default('lowseekai-advertising.currency_icon', 'fas fa-coins')
        ->default('lowseekai-advertising.renewal_enabled', true),

    (new Extend\Policy())
        ->globalPolicy(Access\AdvertisingPolicy::class),
];
