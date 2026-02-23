<?php
declare(strict_types=1);

namespace App\Service;

use League\ColorExtractor\Palette;
use League\ColorExtractor\ColorExtractor;

final class ColorAnalysisService
{
    /**
     * Analyze colors from a thumbnail file.
     * @param string $thumbFilename path to a small image file
     * @param int $top how many top colors to keep in the summary "palette"
     * @param int $hueBuckets number of hue bins (e.g. 36 = 10° each)
     */
    public function analyze(string $thumbFilename, int $top = 5, int $hueBuckets = 36): array
    {
        $palette   = Palette::fromFilename($thumbFilename);
        $extractor = new ColorExtractor($palette);

        // 1) Basic top-N palette (ints)
        $topPalette = $extractor->extract($top); // array<int>

        // 2) Full histogram: color(int) => count
        $hist = [];
        $total = 0;
        foreach ($palette as $rgb => $count) {
            $hist[(int)$rgb] = (int)$count;
            $total += (int)$count;
        }
        if ($total < 1) {
            return [
                'dominant'   => null,
                'palette'    => [],
                'dist'       => [],
                'avg'        => null,
                'hueBuckets' => [],
                '_total'     => 0,
            ];
        }

        // 3) Dominant (max count)
        $dominant = array_key_first(array_slice(self::sortByCountDesc($hist), 0, 1, true));

        // 4) Average color (weighted)
        [$avgR, $avgG, $avgB] = [0.0, 0.0, 0.0];
        foreach ($hist as $rgb => $count) {
            [$r, $g, $b] = self::intToRgb($rgb);
            $avgR += $r * $count;
            $avgG += $g * $count;
            $avgB += $b * $count;
        }
        $avgR = (int)round($avgR / $total);
        $avgG = (int)round($avgG / $total);
        $avgB = (int)round($avgB / $total);
        $avgRgb = self::rgbToInt($avgR, $avgG, $avgB);

        // 5) Distribution rows (rgb, hex, count, ratio, hsl, hueBucket)
        $dist = [];
        $presentBuckets = [];
        $bucketCount = max(1, $hueBuckets);
        $bucketSize = 360.0 / $bucketCount;
        foreach (self::sortByCountDesc($hist) as $rgb => $count) {
            [$r, $g, $b] = self::intToRgb($rgb);
            [$h, $s, $l] = self::rgbToHsl($r, $g, $b); // h: 0..360
            $normalizedHue = fmod($h + 360.0, 360.0);
            $bucket = (int) floor($normalizedHue / $bucketSize);
            if ($bucket >= $bucketCount) {
                $bucket = $bucketCount - 1;
            }
            $presentBuckets[$bucket] = true;
            $dist[] = [
                'rgb'       => $rgb,
                'hex'       => self::rgbToHex($r, $g, $b),
                'count'     => $count,
                'ratio'     => $count / $total,
                'h'         => (int)round($h),
                's'         => (int)round($s),
                'l'         => (int)round($l),
                'hueBucket' => $bucket,
            ];
        }

        // 6) Final object
        return [
            'dominant'   => $dominant,
            'palette'    => array_values($topPalette),
            'dist'       => $dist,
            'avg'        => [
                'rgb' => $avgRgb,
                'hex' => self::rgbToHex($avgR, $avgG, $avgB),
                ...array_combine(['h','s','l'], array_map('intval', self::rgbToHsl($avgR, $avgG, $avgB))),
            ],
            'hueBuckets' => array_map('intval', array_keys($presentBuckets)),
            '_total'     => $total, // optional debugging
        ];
    }

    /** @return array{int,int,int} */
    public static function intToRgb(int $rgb): array
    {
        return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
    }

    public static function rgbToInt(int $r, int $g, int $b): int
    {
        return ($r << 16) + ($g << 8) + $b;
    }

    public static function rgbToHex(int $r, int $g, int $b): string
    {
        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }

    /**
     * @return array{float,float,float} H in [0..360), S in [0..100], L in [0..100]
     */
    public static function rgbToHsl(int $r, int $g, int $b): array
    {
        $r /= 255; $g /= 255; $b /= 255;
        $max = max($r,$g,$b); $min = min($r,$g,$b);
        $h = $s = 0.0;
        $l = ($max + $min) / 2;
        if ($max !== $min) {
            $d = $max - $min;
            $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
            switch ($max) {
                case $r: $h = ($g - $b) / $d + ($g < $b ? 6 : 0); break;
                case $g: $h = ($b - $r) / $d + 2; break;
                case $b: $h = ($r - $g) / $d + 4; break;
            }
            $h *= 60;
        }
        return [fmod($h + 360.0, 360.0), $s * 100.0, $l * 100.0];
    }

    /** @param array<int,int> $hist */
    private static function sortByCountDesc(array $hist): array
    {
        arsort($hist, SORT_NUMERIC);
        return $hist;
    }
}
