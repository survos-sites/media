<?php
declare(strict_types=1);

namespace App\Ai\Result;

final class MediaEnrichment
{
    /**
     * @param string[] $keywords
     * @param string[] $people
     * @param string[] $places
     * @param string[] $organisations
     * @param string[] $subjects
     * @param string[] $speculations
     */
    public function __construct(
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly ?string $summary,
        public readonly ?string $denseSummary,
        public readonly ?string $ocrText,
        public readonly ?string $documentType,
        public readonly ?string $documentSubtype,
        public readonly ?string $contentType,
        public readonly ?string $dateHint,
        public readonly ?string $dateRange,
        public readonly array $keywords,
        public readonly array $people,
        public readonly array $places,
        public readonly array $organisations,
        public readonly array $subjects,
        public readonly bool $hasText,
        public readonly ?float $confidence,
        public readonly array $speculations,
        public readonly ?string $imageUrl,
        public readonly ?string $imageSource,
    ) {
    }

    /**
     * @param list<array{task?:string,result?:array}> $completed
     */
    public static function fromCompleted(array $completed): self
    {
        $byTask = [];
        foreach ($completed as $entry) {
            $task = $entry['task'] ?? null;
            if (!is_string($task) || $task === '') {
                continue;
            }
            if (!isset($entry['result']) || !is_array($entry['result'])) {
                continue;
            }
            if (!empty($entry['result']['failed']) || !empty($entry['result']['skipped'])) {
                continue;
            }
            $byTask[$task] = $entry['result'];
        }

        $enrich = self::arr($byTask['enrich_from_thumbnail'] ?? null);
        $classify = self::arr($byTask['classify'] ?? null);
        $extract = self::arr($byTask['extract_metadata'] ?? null);
        $peoplePlaces = self::arr($byTask['people_and_places'] ?? null);
        $keywordsTask = self::arr($byTask['keywords'] ?? null);
        $generateTitle = self::arr($byTask['generate_title'] ?? null);
        $contextDescription = self::arr($byTask['context_description'] ?? null);
        $basicDescription = self::arr($byTask['basic_description'] ?? null);
        $summarize = self::arr($byTask['summarize'] ?? null);
        $ocrMistral = self::arr($byTask['ocr_mistral'] ?? null);
        $ocr = self::arr($byTask['ocr'] ?? null);
        $handwriting = self::arr($byTask['transcribe_handwriting'] ?? null);

        $imageUrl = self::string($enrich['_debug']['image_url'] ?? null)
            ?? self::string($contextDescription['_debug']['image_url'] ?? null)
            ?? self::string($basicDescription['_debug']['image_url'] ?? null);

        $imageSource = self::string($enrich['_debug']['image_source'] ?? null)
            ?? self::string($contextDescription['_debug']['image_source'] ?? null)
            ?? self::string($basicDescription['_debug']['image_source'] ?? null);

        $hasText = self::bool($enrich['has_text'] ?? null);
        if ($hasText === null) {
            $hasText = self::string($ocrMistral['text'] ?? null) !== null
                || self::string($ocr['text'] ?? null) !== null
                || self::string($handwriting['text'] ?? null) !== null;
        }

        return new self(
            title: self::string($enrich['title'] ?? null) ?? self::string($generateTitle['title'] ?? null),
            description: self::string($enrich['description'] ?? null)
                ?? self::string($contextDescription['description'] ?? null)
                ?? self::string($basicDescription['description'] ?? null),
            summary: self::string($summarize['summary'] ?? null),
            denseSummary: self::string($enrich['dense_summary'] ?? null),
            ocrText: self::string($ocrMistral['text'] ?? null)
                ?? self::string($ocr['text'] ?? null)
                ?? self::string($handwriting['text'] ?? null),
            documentType: self::string($classify['type'] ?? null) ?? self::string($enrich['content_type'] ?? null),
            documentSubtype: self::string($classify['subtype'] ?? null),
            contentType: self::string($enrich['content_type'] ?? null),
            dateHint: self::string($enrich['date_hint'] ?? null),
            dateRange: self::string($extract['dateRange'] ?? null),
            keywords: self::strings($enrich['keywords'] ?? null, $keywordsTask['keywords'] ?? null),
            people: self::strings($enrich['people'] ?? null, $peoplePlaces['people'] ?? null, $extract['people'] ?? null),
            places: self::strings($enrich['places'] ?? null, $peoplePlaces['places'] ?? null, $extract['places'] ?? null),
            organisations: self::strings($peoplePlaces['organisations'] ?? null, $extract['organisations'] ?? null),
            subjects: self::strings($extract['subjects'] ?? null),
            hasText: $hasText,
            confidence: self::float($enrich['confidence'] ?? null),
            speculations: self::strings($enrich['speculations'] ?? null),
            imageUrl: $imageUrl,
            imageSource: $imageSource,
        );
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            title: self::string($data['title'] ?? null),
            description: self::string($data['description'] ?? null),
            summary: self::string($data['summary'] ?? null),
            denseSummary: self::string($data['denseSummary'] ?? null),
            ocrText: self::string($data['ocrText'] ?? null),
            documentType: self::string($data['documentType'] ?? null),
            documentSubtype: self::string($data['documentSubtype'] ?? null),
            contentType: self::string($data['contentType'] ?? null),
            dateHint: self::string($data['dateHint'] ?? null),
            dateRange: self::string($data['dateRange'] ?? null),
            keywords: self::strings($data['keywords'] ?? null),
            people: self::strings($data['people'] ?? null),
            places: self::strings($data['places'] ?? null),
            organisations: self::strings($data['organisations'] ?? null),
            subjects: self::strings($data['subjects'] ?? null),
            hasText: (bool) ($data['hasText'] ?? false),
            confidence: self::float($data['confidence'] ?? null),
            speculations: self::strings($data['speculations'] ?? null),
            imageUrl: self::string($data['imageUrl'] ?? null),
            imageSource: self::string($data['imageSource'] ?? null),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'summary' => $this->summary,
            'denseSummary' => $this->denseSummary,
            'ocrText' => $this->ocrText,
            'documentType' => $this->documentType,
            'documentSubtype' => $this->documentSubtype,
            'contentType' => $this->contentType,
            'dateHint' => $this->dateHint,
            'dateRange' => $this->dateRange,
            'keywords' => $this->keywords,
            'people' => $this->people,
            'places' => $this->places,
            'organisations' => $this->organisations,
            'subjects' => $this->subjects,
            'hasText' => $this->hasText,
            'confidence' => $this->confidence,
            'speculations' => $this->speculations,
            'imageUrl' => $this->imageUrl,
            'imageSource' => $this->imageSource,
        ];
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'y'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'n'], true)) {
                return false;
            }
        }

        return null;
    }

    private static function float(mixed $value): ?float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return string[] */
    private static function strings(mixed ...$values): array
    {
        $out = [];
        foreach ($values as $value) {
            if (!is_array($value)) {
                continue;
            }

            foreach ($value as $item) {
                if (!is_scalar($item)) {
                    continue;
                }

                $string = trim((string) $item);
                if ($string !== '') {
                    $out[$string] = $string;
                }
            }
        }

        return array_values($out);
    }
}
