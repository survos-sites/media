<?php
declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;
use RuntimeException;

use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function rename;
use function sprintf;
use function tempnam;
use function unlink;

final class AtomicFileWriter
{
    public function write(string $targetPath, string $contents, bool $ensureDir = false): void
    {
        if ($targetPath === '') {
            throw new InvalidArgumentException('Target path must not be empty.');
        }

        $dir = dirname($targetPath);

        if ($ensureDir && !is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException(sprintf('Failed to create directory "%s".', $dir));
            }
        }

        if (!is_dir($dir)) {
            throw new RuntimeException(sprintf('Target directory does not exist: "%s".', $dir));
        }

        $tmp = tempnam($dir, '.atomic-');
        if ($tmp === false) {
            throw new RuntimeException(sprintf('Failed to create temp file in "%s".', $dir));
        }

        try {
            $bytes = file_put_contents($tmp, $contents);
            if ($bytes === false) {
                throw new RuntimeException(sprintf('Failed writing temp file "%s".', $tmp));
            }

            if (!rename($tmp, $targetPath)) {
                throw new RuntimeException(sprintf('Atomic rename failed for "%s".', $targetPath));
            }
        } catch (\Throwable $e) {
            if (is_file($tmp)) {
                unlink($tmp);
            }
            throw $e;
        }
    }
}
