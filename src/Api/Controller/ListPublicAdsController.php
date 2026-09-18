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

        if ($slot !== '' && ! $this->settings->slotEnabled($slot)) {
            return new JsonResponse(['data' => [], 'meta' => ['slots' => $this->slotMeta()]]);
        }

        $ads = Ad::query()
            ->with('user')
            ->where('status', 'approved')
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', Carbon::now('Asia/Shanghai'));
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', Carbon::now('Asia/Shanghai'));
            })
            ->when($slot !== '', fn ($query) => $query->where('slot_key', $slot))
            ->orderByRaw('slot_position IS NULL')
            ->orderBy('slot_position')
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get();

        $ads = $ads
            ->filter(fn (Ad $ad) => $this->settings->slotEnabled((string) $ad->slot_key))
            ->groupBy('slot_key')->flatMap(function ($items, $key) {
            return $items->take($this->settings->slotCount((string) $key));
        })->values();

        return new JsonResponse([
            'data' => $ads->map(fn (Ad $ad) => $this->serializer->serialize($ad))->values()->all(),
            'meta' => ['slots' => $this->slotMeta()],
        ]);
    }

    protected function slotMeta(): array
    {
        $reserved = Ad::query()
            ->whereIn('status', ['pending', 'approved'])
            ->whereNotNull('slot_position')
            ->get(['slot_key', 'slot_position'])
            ->groupBy('slot_key')
            ->map(fn ($items) => $items->pluck('slot_position')->map(fn ($position) => (int) $position)->values()->all())
            ->all();

        return collect($this->settings->slots())->map(function (array $slot) use ($reserved) {
            $reservedPositions = $reserved[$slot['key']] ?? [];
            $positions = [];

            for ($position = 1; $position <= $slot['capacity']; $position++) {
                $positions[] = [
                    'position' => $position,
                    'available' => $slot['enabled'] && ! in_array($position, $reservedPositions, true),
                ];
            }

            return $slot + ['positions' => $positions];
        })->values()->all();
    }
}
