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

use League\Flysystem\FilesystemOperator;

final class AtomicFileWriter
{
    public function __construct(
        private readonly FilesystemOperator $filesystem,
    ) {
    }

    public function write(string $targetPath, string $contents): void
    {
        if ($targetPath === '') {
            throw new InvalidArgumentException('Target path must not be empty.');
        }

        if ($targetPath === '') {
            throw new InvalidArgumentException('Target path must not be empty.');
        }

        $tmpPath = $targetPath . '.tmp';

        try {
            $this->filesystem->write($tmpPath, $contents);
            $this->filesystem->move($tmpPath, $targetPath);
        } catch (\Throwable $e) {
            if ($this->filesystem->fileExists($tmpPath)) {
                $this->filesystem->delete($tmpPath);
            }
            throw $e;
        }
    }
}
