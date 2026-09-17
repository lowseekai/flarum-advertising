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

class UpdateAdminAdController implements RequestHandlerInterface
{
    public function __construct(
        protected AdRepository $ads,
        protected AdSerializer $serializer,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest() || ! $actor->hasPermission('lowseekai-advertising.manage')) {
            throw new PermissionDeniedException();
        }

        // Flarum 2 stores FastRoute parameters in `routeParameters`; they are
        // not copied to individual request attributes.
        $id = (int) Arr::get($request->getAttribute('routeParameters'), 'id');
        $ad = Ad::query()->findOrFail($id);
        $attributes = (array) Arr::get($request->getParsedBody(), 'data.attributes', []);
        $ad = $this->ads->updateByAdmin($actor, $ad, $attributes);

        return new JsonResponse(['data' => $this->serializer->serialize($ad)]);
    }
}
