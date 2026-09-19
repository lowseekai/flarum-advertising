<?php

declare(strict_types=1);

namespace Lowseekai\Advertising\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Laminas\Diactoros\Response\JsonResponse;
use Lowseekai\Advertising\Support\AdvertisingSettings;
use Lowseekai\Advertising\Support\ImageUploader;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class UploadImageController implements RequestHandlerInterface
{
    public function __construct(
        protected ImageUploader $uploader,
        protected AdvertisingSettings $settings,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest() || (
            ! $actor->hasPermission('lowseekai-advertising.submit')
            && ! $actor->hasPermission('lowseekai-advertising.manage')
        )) {
            throw new PermissionDeniedException();
        }

        $result = $this->uploader->upload($request->getUploadedFiles()['image'] ?? null);

        return new JsonResponse(['data' => $result]);
    }
}
