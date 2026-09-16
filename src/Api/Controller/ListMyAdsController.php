<?php

declare(strict_types=1);

namespace Lowseekai\Advertising\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Laminas\Diactoros\Response\JsonResponse;
use Lowseekai\Advertising\Model\Ad;
use Lowseekai\Advertising\Support\AdSerializer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ListMyAdsController implements RequestHandlerInterface
{
    public function __construct(protected AdSerializer $serializer) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest()) {
            throw new PermissionDeniedException();
        }

        $ads = Ad::query()
            ->with('user')
            ->where('user_id', $actor->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return new JsonResponse([
            'data' => $ads->map(fn (Ad $ad) => $this->serializer->serialize($ad))->values()->all(),
        ]);
    }
}
