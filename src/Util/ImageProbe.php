<?php
declare(strict_types=1);

namespace App\Util;

/**
 * Fast dimension/mime sniffing from in-memory bytes.
 * - Tries lightweight header parsing for JPEG/PNG/GIF/WebP.
 * - Falls back to getimagesizefromstring() for anything else (e.g., AVIF).
 */
final class ImageProbe
{
    /** @return array{ok:bool,width:?int,height:?int,mime:?string,ext:?string} */
    public static function probe(string $bytes): array
    {
        if ($bytes === '') {
            return ['ok' => false, 'width' => null, 'height' => null, 'mime' => null, 'ext' => null];
        }

        // PNG
        if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0) {
            // IHDR follows: length(4) 'IHDR'(4) then width(4) height(4) big-endian
            if (\strlen($bytes) >= 24) {
                $w = unpack('N', substr($bytes, 16, 4))[1] ?? null;
                $h = unpack('N', substr($bytes, 20, 4))[1] ?? null;
                if ($w && $h) {
                    return ['ok' => true, 'width' => $w, 'height' => $h, 'mime' => 'image/png', 'ext' => 'png'];
                }
            }
        }

        // GIF
        if (strncmp($bytes, "GIF87a", 6) === 0 || strncmp($bytes, "GIF89a", 6) === 0) {
            if (\strlen($bytes) >= 10) {
                $w = unpack('v', substr($bytes, 6, 2))[1] ?? null;   // little-endian
                $h = unpack('v', substr($bytes, 8, 2))[1] ?? null;
                if ($w && $h) {
                    return ['ok' => true, 'width' => $w, 'height' => $h, 'mime' => 'image/gif', 'ext' => 'gif'];
                }
            }
        }

        // JPEG (search SOF markers)
        if (strncmp($bytes, "\xFF\xD8", 2) === 0) {
            $len = \strlen($bytes);
            $pos = 2;
            while ($pos + 1 < $len) {
                if ($bytes[$pos] !== "\xFF") { $pos++; continue; }
                // skip fill FFs
                while ($pos < $len && $bytes[$pos] === "\xFF") { $pos++; }
                if ($pos >= $len) break;
                $marker = ord($bytes[$pos]); $pos++;
                // markers without length
                if ($marker === 0xD8 || $marker === 0xD9) { continue; }
                if ($pos + 1 >= $len) break;
                $segLen = (ord($bytes[$pos]) << 8) | ord($bytes[$pos+1]); $pos += 2;
                if ($segLen < 2 || $pos + $segLen - 2 > $len) break;

                // SOF0..3,5..7,9..11,13..15 carry dimensions
                if (in_array($marker, [0xC0,0xC1,0xC2,0xC3,0xC5,0xC6,0xC7,0xC9,0xCA,0xCB,0xCD,0xCE,0xCF], true)) {
                    if ($segLen >= 7) {
                        $h = (ord($bytes[$pos+1]) << 8) | ord($bytes[$pos+2]);
                        $w = (ord($bytes[$pos+3]) << 8) | ord($bytes[$pos+4]);
                        if ($w && $h) {
                            return ['ok' => true, 'width' => $w, 'height' => $h, 'mime' => 'image/jpeg', 'ext' => 'jpg'];
                        }
                    }
                    break;
                }
                $pos += $segLen - 2;
            }
        }

        // WebP (RIFF container)
        if (\strlen($bytes) >= 16 && strncmp($bytes, "RIFF", 4) === 0 && substr($bytes, 8, 4) === "WEBP") {
            $fourcc = substr($bytes, 12, 4);
            // VP8X: extended, canvas size at bytes 24..29: (w-1)|(h-1) as 3-byte little-endian
            if ($fourcc === "VP8X" && \strlen($bytes) >= 30) {
                $w = self::u24le(substr($bytes, 24, 3)) + 1;
                $h = self::u24le(substr($bytes, 27, 3)) + 1;
                return ['ok' => true, 'width' => $w, 'height' => $h, 'mime' => 'image/webp', 'ext' => 'webp'];
            }
            // VP8 (lossy): frame header holds width/height (little-endian, 14 bits each)
            if ($fourcc === "VP8 " && \strlen($bytes) >= 30) {
                // Simple parse: look near 26..29 per VP8 frame header
                $w = (ord($bytes[26]) | (ord($bytes[27]) << 8)) & 0x3FFF;
                $h = (ord($bytes[28]) | (ord($bytes[29]) << 8)) & 0x3FFF;
                if ($w && $h) {
                    return ['ok' => true, 'width' => $w, 'height' => $h, 'mime' => 'image/webp', 'ext' => 'webp'];
                }
            }
            // VP8L (lossless): width = (bits 0..13) +1, height = (bits 14..27)+1 from 5 bytes starting at 21
            if ($fourcc === "VP8L" && \strlen($bytes) >= 25) {
                $b0 = ord($bytes[21]); $b1 = ord($bytes[22]); $b2 = ord($bytes[23]); $b3 = ord($bytes[24]);
                $w = 1 + (($b1 & 0x3F) << 8 | $b0);
                $h = 1 + (($b3 & 0x0F) << 10 | ($b2 << 2) | (($b1 & 0xC0) >> 6));
                return ['ok' => true, 'width' => $w, 'height' => $h, 'mime' => 'image/webp', 'ext' => 'webp'];
            }
        }

        // Fallback: PHP's built-in (fast, no full decode). Handles many formats (incl. WEBP; AVIF support depends on build).
        if (function_exists('getimagesizefromstring')) {
            $info = @getimagesizefromstring($bytes, $extra);
            if (is_array($info)) {
                return [
                    'ok' => true,
                    'width'  => $info[0] ?? null,
                    'height' => $info[1] ?? null,
                    'mime'   => $info['mime'] ?? null,
                    'ext'    => self::extFromMime($info['mime'] ?? null),
                ];
            }
        }

        return ['ok' => false, 'width' => null, 'height' => null, 'mime' => null, 'ext' => null];
    }

    private static function u24le(string $s): int
    {
        return ord($s[0]) | (ord($s[1]) << 8) | (ord($s[2]) << 16);
    }

    public static function extFromMime(?string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            default      => null,
        };
    }
}
