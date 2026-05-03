<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Zenstruck\Bytes;

final class BytesExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('bytes', [$this, 'humanizeBytes']),
            new TwigFilter('bytes_human', [$this, 'humanizeBytes']),
        ];
    }

    public function humanizeBytes(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        try {
            return (string) Bytes::parse($value);
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}
