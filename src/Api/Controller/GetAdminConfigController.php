<?php

declare(strict_types=1);

namespace Lowseekai\Advertising\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Laminas\Diactoros\Response\JsonResponse;
use Lowseekai\Advertising\Support\AdvertisingSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class GetAdminConfigController implements RequestHandlerInterface
{
    public function __construct(protected AdvertisingSettings $settings) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest() || ! $actor->hasPermission('lowseekai-advertising.manage')) {
            throw new PermissionDeniedException();
        }

        return new JsonResponse(['data' => $this->data()]);
    }

    protected function data(): array
    {
        return [
            'enabled' => $this->settings->enabled(),
            'sidebarPricePerDay' => $this->settings->pricePerDay('sidebar'),
            'topPricePerDay' => $this->settings->pricePerDay('top'),
            'minDurationDays' => $this->settings->minDurationDays(),
            'maxDurationDays' => $this->settings->maxDurationDays(),
            'maxImageSizeKb' => $this->settings->maxImageSizeKb(),
            'currencyName' => $this->settings->currencyName(),
            'currencyIcon' => $this->settings->currencyIcon(),
        ];
    }
}
