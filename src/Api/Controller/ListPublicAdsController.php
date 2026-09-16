<?php

declare(strict_types=1);

namespace Lowseekai\Advertising\Api\Controller;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Lowseekai\Advertising\Model\Ad;
use Lowseekai\Advertising\Support\AdSerializer;
use Lowseekai\Advertising\Support\AdvertisingSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ListPublicAdsController implements RequestHandlerInterface
{
    public function __construct(
        protected AdSerializer $serializer,
        protected AdvertisingSettings $settings,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request);
        if (! $this->settings->enabled()) {
            return new JsonResponse(['data' => []]);
        }

        $slot = (string) ($request->getQueryParams()['slot'] ?? '');

        $ads = Ad::query()
            ->with('user')
            ->where('status', 'approved')
            ->where('is_visible', true)
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', Carbon::now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', Carbon::now());
            })
            ->when($slot !== '', fn ($query) => $query->where('slot_key', $slot))
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return new JsonResponse([
            'data' => $ads->map(fn (Ad $ad) => $this->serializer->serialize($ad))->values()->all(),
        ]);
    }
}
