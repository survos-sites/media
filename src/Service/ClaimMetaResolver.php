<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Pure predicate-priority resolution over a subject's claims — the same logic used both by
 * {@see \App\Twig\AssetClaimsExtension} (display: asset_meta() in the search hit card) and by
 * {@see ClaimSearchSync} (search: denormalizing the same fields onto Asset's FTS columns).
 * Kept here, shared, so the two never drift — a card showing a caption the search vector
 * doesn't know about (or vice versa) is exactly the bug this class exists to prevent.
 */
final class ClaimMetaResolver
{
    /**
     * @param array<string, list<mixed>> $claims predicate => list of values (newest first)
     * @return array{caption: ?string, prose: ?string, subjects: list<string>, type: ?string, year: ?string}
     */
    public function resolve(array $claims): array
    {
        $caption = $this->first($claims, 'ai:caption')
            ?? $this->first($claims, 'observe:caption')
            ?? $this->first($claims, 'dcterms:title');

        $denseSummary = $this->first($claims, 'ai:denseSummary');
        $prose = $this->first($claims, 'ai:observationProse')
            ?? $this->first($claims, 'observe:description')
            ?? $denseSummary;

        $subjects = $this->strings($claims, 'dcterms:subject', 'observe:tag');

        return [
            'caption' => $caption,
            'prose' => $prose,
            'subjects' => $subjects,
            'type' => $this->first($claims, 'dcterms:type') ?? $this->first($claims, 'observe:classification'),
            'year' => $this->mineYear($caption, $denseSummary, $subjects),
        ];
    }

    /** @param array<string, list<mixed>> $claims */
    private function first(array $claims, string $predicate): ?string
    {
        foreach ($claims[$predicate] ?? [] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param array<string, list<mixed>> $claims
     * @return list<string>
     */
    private function strings(array $claims, string ...$predicates): array
    {
        $out = [];
        foreach ($predicates as $predicate) {
            foreach ($claims[$predicate] ?? [] as $value) {
                if (is_scalar($value)) {
                    $string = trim((string) $value);
                    if ($string !== '') {
                        $out[$string] = $string;
                    }
                }
            }
        }

        return array_values($out);
    }

    /** @param list<string> $subjects */
    private function mineYear(?string $caption, ?string $denseSummary, array $subjects): ?string
    {
        foreach ([$caption, $denseSummary, implode(' ', $subjects)] as $text) {
            if (is_string($text) && preg_match('/\b(1[89]\d{2}|20\d{2})\b/', $text, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }
}
