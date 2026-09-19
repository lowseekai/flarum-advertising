<?php

declare(strict_types=1);

namespace Lowseekai\Advertising\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Laminas\Diactoros\Response\JsonResponse;
use Lowseekai\Advertising\Model\Ad;
use Lowseekai\Advertising\Support\AdvertisingSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ListSlotAvailabilityController implements RequestHandlerInterface
{
    public function __construct(protected AdvertisingSettings $settings) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest() || ! $actor->hasPermission('lowseekai-advertising.submit')) {
            throw new PermissionDeniedException();
        }

        $slot = $this->settings->normalizeSlotKey((string) ($request->getQueryParams()['slot'] ?? 'right_sidebar'));
        if (! array_key_exists($slot, AdvertisingSettings::SLOT_LABELS)) {
            return new JsonResponse(['errors' => [['detail' => '广告位无效。']]], 422);
        }

        $reserved = Ad::query()
            ->where('slot_key', $slot)
            ->whereIn('status', ['pending', 'approved'])
            ->whereNotNull('slot_position')
            ->pluck('slot_position')
            ->map(fn ($position) => (int) $position)
            ->all();

        $positions = [];
        for ($position = 1; $position <= $this->settings->slotCount($slot); $position++) {
            $positions[] = [
                'position' => $position,
                'available' => $this->settings->slotEnabled($slot) && ! in_array($position, $reserved, true),
            ];
        }

        return new JsonResponse([
            'data' => [
                'slot' => $slot,
                'enabled' => $this->settings->slotEnabled($slot),
                'capacity' => $this->settings->slotCount($slot),
                'pricePerMonth' => $this->settings->pricePerMonth($slot),
                'positions' => $positions,
            ],
        ]);
    }
}
