<?php

declare(strict_types=1);

namespace Lowseekai\Advertising\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Lowseekai\Advertising\Model\Ad;
use Lowseekai\Advertising\Support\AdRepository;
use Lowseekai\Advertising\Support\AdSerializer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RenewAdController implements RequestHandlerInterface
{
    public function __construct(
        protected AdRepository $ads,
        protected AdSerializer $serializer,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest()) {
            throw new PermissionDeniedException();
        }

        $id = (int) Arr::get($request->getAttribute('routeParameters'), 'id');
        $ad = Ad::query()->findOrFail($id);
        $ad = $this->ads->renew($actor, $ad);

        return new JsonResponse(['data' => $this->serializer->serialize($ad)]);
    }
}
