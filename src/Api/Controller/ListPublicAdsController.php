<?php

declare(strict_types=1);

namespace Lowseekai\Advertising\Api\Controller;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Lowseekai\Advertising\Model\Ad;
use Lowseekai\Advertising\Support\AdRepository;
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
        protected AdRepository $ads,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if (! $this->settings->enabled()) {
            return new JsonResponse(['data' => []]);
        }

        $slot = $this->settings->normalizeSlotKey((string) ($request->getQueryParams()['slot'] ?? ''));

        if ($slot !== '' && ! array_key_exists($slot, AdvertisingSettings::SLOT_LABELS)) {
            return new JsonResponse(['data' => [], 'meta' => ['slots' => $this->slotMeta($actor)]]);
        }

        if ($slot !== '' && ! $this->settings->slotEnabled($slot)) {
            return new JsonResponse(['data' => [], 'meta' => ['slots' => $this->slotMeta($actor)]]);
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
            'data' => $ads->map(fn (Ad $ad) => $this->serializePublicAd($ad, $actor))->values()->all(),
            'meta' => ['slots' => $this->slotMeta($actor)],
        ]);
    }

    protected function serializePublicAd(Ad $ad, User $actor): array
    {
        $data = $this->serializer->serialize($ad);
        $data['canReserve'] = $this->canReserveAd($ad, $actor);

        return $data;
    }

    protected function canReserveAd(Ad $ad, User $actor): bool
    {
        if ($actor->isGuest() || ! $ad->ends_at || (bool) $ad->auto_renew_enabled) {
            return false;
        }

        $slotKey = $this->settings->normalizeSlotKey((string) $ad->slot_key);
        $now = Carbon::now('Asia/Shanghai');

        if ($ad->ends_at->lte($now) || $ad->ends_at->gt($now->copy()->addDays($this->settings->reservationLeadDays()))) {
            return false;
        }

        return $this->ads->canReserveSlot($slotKey, $actor);
    }

    protected function slotMeta(User $actor): array
    {
        $reserved = Ad::query()
            ->whereIn('status', ['pending', 'approved'])
            ->whereNotNull('slot_position')
            ->get(['slot_key', 'slot_position'])
            ->groupBy('slot_key')
            ->map(fn ($items) => $items->pluck('slot_position')->map(fn ($position) => (int) $position)->values()->all())
            ->all();

        return collect($this->settings->slots())->map(function (array $slot) use ($reserved, $actor) {
            $reservedPositions = $reserved[$slot['key']] ?? ($slot['key'] === 'right_sidebar' ? ($reserved['sidebar'] ?? []) : []);
            $positions = [];

            for ($position = 1; $position <= $slot['capacity']; $position++) {
                $positions[] = [
                    'position' => $position,
                    'available' => $slot['enabled'] && ! in_array($position, $reservedPositions, true),
                ];
            }

            $availableCount = collect($positions)->where('available', true)->count();
            $earliestRelease = $this->ads->earliestReservableReleaseAt($slot['key']);
            $queueCount = $this->ads->reservationQueueCount($slot['key']);
            $reservable = $availableCount === 0
                && ! $actor->isGuest()
                && $this->ads->canReserveSlot($slot['key'], $actor);

            return $slot + [
                'positions' => $positions,
                'availableCount' => $availableCount,
                'isFull' => $availableCount === 0,
                'reservable' => $reservable,
                'earliestReleaseAt' => $earliestRelease?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                'reservationQueueCount' => $queueCount,
                'myReservationQueuePosition' => $actor->isGuest() ? null : $this->userQueuePosition($slot['key'], $actor),
            ];
        })->values()->all();
    }

    protected function userQueuePosition(string $slotKey, User $actor): ?int
    {
        $reservation = Ad::query()
            ->where('is_reservation', true)
            ->where('slot_key', $slotKey)
            ->where('user_id', (int) $actor->id)
            ->whereIn('status', ['pending', 'reserved'])
            ->orderBy('reviewed_at')
            ->orderBy('id')
            ->first();

        if (! $reservation) {
            return null;
        }

        if ($reservation->status === 'pending') {
            return null;
        }

        return (int) Ad::query()
            ->where('is_reservation', true)
            ->where('slot_key', $slotKey)
            ->where('status', 'reserved')
            ->where(function ($query) use ($reservation) {
                $query->where('reviewed_at', '<', $reservation->reviewed_at)
                    ->orWhere(function ($query) use ($reservation) {
                        $query->where('reviewed_at', $reservation->reviewed_at)
                            ->where('id', '<=', (int) $reservation->id);
                    });
            })
            ->count();
    }
}
