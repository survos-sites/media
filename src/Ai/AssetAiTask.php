<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Canonical names for every AI task that can appear in Asset::$aiQueue.
 *
 * Image-level tasks (fast, per-scan) should be run during ingest.
 * Instance-level tasks (slow, LLM-tier) are run via the ai_task workflow transition.
 *
 * The string value is what gets stored in the JSON aiQueue / aiCompleted columns.
 */
enum AssetAiTask: string
{
    // ── Image-level (OpenCV / OCR tier) ──────────────────────────────────────

    /** Plain Tesseract / local OCR — extract raw text from a scan. */
    case OCR = 'ocr';

    /** Mistral OCR with document-layout awareness (bounding boxes, columns). */
    case OCR_MISTRAL = 'ocr_mistral';

    /**
     * Parse the structural layout of a complex page: columns, tables, headings,
     * captions. Derived from the Mistral OCR raw_response blocks — no additional
     * API call if OCR_MISTRAL has already run; falls back to a fresh Mistral call.
     */
    case LAYOUT = 'layout';

    // ── Instance-level (LLM / vision-model tier) ─────────────────────────────

    /**
     * Generic visual description with no domain context.
     * e.g. "A white embroidered patch with a red border and an eagle motif."
     */
    case BASIC_DESCRIPTION = 'basic_description';

    /**
     * Context-aware description that leverages known metadata
     * (object type, collection, provenance) for richer prose.
     * e.g. "BSA Lodge 247 Order of the Arrow flap patch, circa 1970s."
     */
    case CONTEXT_DESCRIPTION = 'context_description';

    /**
     * Classify the document / object type.
     * e.g. letter, postcard, photograph, map, patch, newspaper_clipping.
     */
    case CLASSIFY = 'classify';

    /**
     * Extract structured metadata: date range, people mentioned,
     * places, subjects, language.
     */
    case EXTRACT_METADATA = 'extract_metadata';

    /**
     * Generate a short human-readable title suitable for a catalogue record.
     */
    case GENERATE_TITLE = 'generate_title';

    /**
     * Extract named entities — people and places — from the image or OCR text.
     * e.g. ["George Washington", "Brooklyn Bridge", "Boise, ID"]
     */
    case PEOPLE_AND_PLACES = 'people_and_places';

    /**
     * Extract a flat keyword list (topics, objects, activities, era/style).
     */
    case KEYWORDS = 'keywords';

    /**
     * Transcribe handwritten text, using all pages of the instance for context.
     */
    case TRANSCRIBE_HANDWRITING = 'transcribe_handwriting';

    /**
     * Translate the text content (OCR or transcription) to English.
     * Useful for foreign-language material (e.g. Soviet Life articles).
     */
    case TRANSLATE = 'translate';

    /**
     * Generate a 2–4 sentence prose summary of the document / object.
     */
    case SUMMARIZE = 'summarize';

    /**
     * Single-pass thumbnail enrichment: title + description + keywords +
     * people + places + content_type + has_text + dense_summary.
     *
     * Replaces running BasicDescription + Keywords + GenerateTitle +
     * PeopleAndPlaces + Classify separately — ~80% cheaper.
     * Runs sync (a few seconds). Supports confidence levels and basis
     * for uncertain inferences.
     */
    case ENRICH_FROM_THUMBNAIL = 'enrich_from_thumbnail';

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Quick pipeline: single-pass enrichment + OCR if text is detected.
     * Runs sync — a few seconds per image.
     */
    public static function quickScanPipeline(): array
    {
        return [
            self::ENRICH_FROM_THUMBNAIL,
            self::OCR,
        ];
    }

    /**
     * Full pipeline: single-pass enrichment + OCR + translate + summarize.
     * ENRICH_FROM_THUMBNAIL replaces the old 5-task approach.
     */
    public static function fullEnrichmentPipeline(): array
    {
        return [
            self::ENRICH_FROM_THUMBNAIL,
            self::OCR,
            self::TRANSLATE,
            self::SUMMARIZE,
            // Legacy tasks kept for backward compat / comparison
            self::CONTEXT_DESCRIPTION,
            self::EXTRACT_METADATA,
            self::PEOPLE_AND_PLACES,
            self::KEYWORDS,
        ];
    }
}
