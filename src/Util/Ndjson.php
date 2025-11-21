<?php
declare(strict_types=1);

namespace App\Util;

final class Ndjson
{
    /**
     * Stream rows (each line is a JSON object). Skips blank lines.
     *
     * @return \Generator<array<string,mixed>>
     */
    public static function read(string $path, bool $associative=true): \Generator
    {
        $fh = new \SplFileObject($path, 'r');
        $fh->setFlags(\SplFileObject::DROP_NEW_LINE | \SplFileObject::SKIP_EMPTY);
        foreach ($fh as $line) {
            if ($line === null || $line === '' || $line === false) { continue; }
            /** @var array<string,mixed> $row */
            assert(is_string($line), "line is not a string");
            $row = json_decode($line, $associative, flags: JSON_THROW_ON_ERROR);
            yield $row;
        }
    }

    /** Append one row to the NDJSON file. */
    public static function writeRow(string $path, array $row): void
    {
        $out = fopen($path, 'ab');
        if (!$out) {
            throw new \RuntimeException('Cannot open ' . $path);
        }
        fwrite($out, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n");
        fclose($out);
    }
}
