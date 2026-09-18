<?php

declare(strict_types=1);

namespace Lowseekai\Advertising\Support;

use Flarum\Foundation\Paths;
use Flarum\Foundation\ValidationException;
use Psr\Http\Message\UploadedFileInterface;

class ImageUploader
{
    public function __construct(
        protected Paths $paths,
        protected AdvertisingSettings $settings,
    ) {
    }

    public function upload(?UploadedFileInterface $file): array
    {
        if (! $file) {
            throw new ValidationException(['image' => '请先选择广告图片。']);
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException(['image' => '图片上传失败，请重新选择。']);
        }

        $maxBytes = $this->settings->maxImageSizeKb() * 1024;
        if ($file->getSize() !== null && $file->getSize() > $maxBytes) {
            throw new ValidationException(['image' => sprintf('图片不能超过 %d KB。', $this->settings->maxImageSizeKb())]);
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'ls_ad_');
        if ($tmpPath === false) {
            throw new ValidationException(['image' => '临时文件创建失败，请稍后再试。']);
        }

        $file->moveTo($tmpPath);

        try {
            $info = @getimagesize($tmpPath);
            if ($info === false) {
                throw new ValidationException(['image' => '图片文件无效，请上传 GIF、JPG 或 PNG 图片。']);
            }

            $mime = strtolower((string) ($info['mime'] ?? ''));
            $extensions = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
            ];

            if (! isset($extensions[$mime])) {
                throw new ValidationException(['image' => '仅支持 GIF、JPG、JPEG、PNG 格式的图片。']);
            }

            $filename = date('YmdHis').'-'.bin2hex(random_bytes(8)).'.'.$extensions[$mime];
            $relativePath = 'assets/lowseekai-advertising/'.$filename;
            $targetPath = rtrim($this->paths->public, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

            if (! is_dir(dirname($targetPath)) && ! mkdir(dirname($targetPath), 0775, true) && ! is_dir(dirname($targetPath))) {
                throw new ValidationException(['image' => '图片目录创建失败。']);
            }

            if (! rename($tmpPath, $targetPath)) {
                throw new ValidationException(['image' => '图片保存失败。']);
            }

            return [
                'path' => '/'.$relativePath,
                'url' => '/'.$relativePath,
                'width' => (int) $info[0],
                'height' => (int) $info[1],
            ];
        } finally {
            if (is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }
}
