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
            'sidebarEnabled' => $this->settings->slotEnabled('sidebar'),
            'topEnabled' => $this->settings->slotEnabled('top'),
            'sidebarSlots' => $this->settings->slotCount('sidebar'),
            'topSlots' => $this->settings->slotCount('top'),
            'sidebarPricePerMonth' => $this->settings->pricePerMonth('sidebar'),
            'topPricePerMonth' => $this->settings->pricePerMonth('top'),
            'durationPlans' => $this->settings->durationPlans(),
            'maxImageSizeKb' => $this->settings->maxImageSizeKb(),
            'currencyName' => $this->settings->currencyName(),
            'currencyIcon' => $this->settings->currencyIcon(),
            'renewalEnabled' => $this->settings->renewalEnabled(),
        ];
    }
}
