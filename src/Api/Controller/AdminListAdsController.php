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

class AdminListAdsController implements RequestHandlerInterface
{
    public function __construct(protected AdSerializer $serializer) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest() || ! $actor->hasPermission('lowseekai-advertising.manage')) {
            throw new PermissionDeniedException();
        }

        $params = $request->getQueryParams();
        $status = trim((string) ($params['status'] ?? ''));
        $query = Ad::query()->with('user')->orderByDesc('id');

        if ($status !== '' && in_array($status, ['pending', 'approved', 'rejected', 'expired'], true)) {
            $query->where('status', $status);
        }

        $ads = $query->limit(200)->get();

        return new JsonResponse([
            'data' => $ads->map(fn (Ad $ad) => $this->serializer->serialize($ad))->values()->all(),
        ]);
    }
}
