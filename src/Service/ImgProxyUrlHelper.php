<?php

declare(strict_types=1);

namespace App\Service;

use Mperonnet\ImgProxy\Options\Height;
use Mperonnet\ImgProxy\Options\Quality;
use Mperonnet\ImgProxy\Options\Resize;
use Mperonnet\ImgProxy\Options\Width;
use Mperonnet\ImgProxy\UrlBuilder;
use Survos\MediaBundle\Service\MediaUrlGenerator;

final class ImgProxyUrlHelper
{
    public static function small(?string $source): ?string
    {
        return self::build($source, 192, 192, 'webp', 80);
    }

    public static function build(?string $source, int $width, int $height, string $format = 'webp', int $quality = 80): ?string
    {
        $source = is_string($source) ? trim($source) : '';
        if ($source === '') {
            return null;
        }

        $host = trim((string) (getenv('IMGPROXY_HOST') ?: 'https://imgproxy.survos.com'));
        $key = trim((string) (getenv('IMGPROXY_KEY') ?: ''));
        $salt = trim((string) (getenv('IMGPROXY_SALT') ?: ''));

        $builder = ($key !== '' && $salt !== '')
            ? UrlBuilder::signed($key, $salt)
            : new UrlBuilder();

        $path = $builder
            ->usePlain()
            ->with(
                new Resize('fit'),
                new Width($width),
                new Height($height),
                new Quality($quality),
            )
            ->url($source, $format);

        return rtrim($host, '/') . $path;
    }
}
