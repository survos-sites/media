<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Central place to decide which presets you want for an Asset.
 * You can extend to choose formats per mime, etc.
 */
final class VariantPlan
{
    const DEFAULT_PRESETS = ['small','medium'];
    /**
     * @return list<string> e.g. ['thumb','small','medium']
     */
    public function requiredPresetsForAsset(string $mimeOrNull): array
    {
        if ($mimeOrNull !== null && !str_starts_with($mimeOrNull, 'image/')) {
            return []; // non-images: skip
        }
        return self::DEFAULT_PRESETS;
    }
}
