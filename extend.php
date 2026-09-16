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
use Lowseekai\Advertising\Api\Controller\SaveAdminConfigController;
use Lowseekai\Advertising\Api\Controller\UpdateAdminAdController;
use Lowseekai\Advertising\Api\Controller\UploadImageController;
use Lowseekai\Advertising\Console\ExpireAdsCommand;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less')
        ->route('/advertising', 'lowseekai-advertising.index'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('api'))
        ->get('/advertising/public/ads', 'lowseekai-advertising.public.ads', ListPublicAdsController::class)
        ->get('/advertising/me/ads', 'lowseekai-advertising.me.ads', ListMyAdsController::class)
        ->post('/advertising/upload-image', 'lowseekai-advertising.upload-image', UploadImageController::class)
        ->post('/advertising/ads', 'lowseekai-advertising.ads.create', CreateAdController::class)
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
                ->get(fn () => resolve(Support\AdvertisingSettings::class)->enabled()),
            Schema\Str::make('lowseekaiAdvertisingCurrencyName')
                ->get(fn () => resolve(Support\AdvertisingSettings::class)->currencyName()),
            Schema\Str::make('lowseekaiAdvertisingCurrencyIcon')
                ->get(fn () => resolve(Support\AdvertisingSettings::class)->currencyIcon()),
        ]),

    (new Extend\Console())
        ->command(ExpireAdsCommand::class)
        ->schedule(ExpireAdsCommand::class, function (Event $event) {
            $event->hourly();
        }),

    (new Extend\Settings())
        ->default('lowseekai-advertising.enabled', true)
        ->default('lowseekai-advertising.sidebar_price_per_day', 20)
        ->default('lowseekai-advertising.top_price_per_day', 30)
        ->default('lowseekai-advertising.min_duration_days', 1)
        ->default('lowseekai-advertising.max_duration_days', 30)
        ->default('lowseekai-advertising.max_image_size_kb', 2048)
        ->default('lowseekai-advertising.currency_name', '积分')
        ->default('lowseekai-advertising.currency_icon', 'fas fa-coins'),

    (new Extend\Policy())
        ->globalPolicy(Access\AdvertisingPolicy::class),
];
