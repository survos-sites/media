<?php
declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Asset;
use Survos\StateBundle\Attribute\Place;
use Survos\StateBundle\Attribute\Transition;
use Survos\StateBundle\Attribute\Workflow;

/**
 * SAIS Asset workflow — images, audio, video.
 * Analysis happens BEFORE archive to allow stamping object metadata at create time.
 * Guards use Symfony ExpressionLanguage (subject = Asset).
 */
#[Workflow(supports: [Asset::class], name: self::WORKFLOW_NAME)]
class AssetFlow
{
    public const WORKFLOW_NAME = 'asset';

    // ─────────────── Places ───────────────

    #[Place(
        initial: true,
        info: 'Registered/added',
        next: [self::TRANSITION_DOWNLOAD]
    )]
    public const PLACE_NEW = 'new';

    #[Place(
        info: 'Fetched to temp; MIME sniffed/probed',
        next: [self::TRANSITION_ANALYZE]
    )]
    public const PLACE_DOWNLOADED = 'downloaded';

//    #[Place(
//        info: 'Basic checks passed (type/size/codec)',
//        next: [self::TRANSITION_ANALYZE]
//    )]
//    public const PLACE_VALIDATED = 'validated';

    #[Place(
        info: 'Features computed (blurhash/palette/pHash/probe)',
        next: [self::TRANSITION_ARCHIVE]
    )]
    public const PLACE_ANALYZED = 'analyzed';

     #[Place(
         info: 'Original stored durably with metadata'
     )]
     public const PLACE_ARCHIVED = 'archived';

     #[Place(info: 'All done')]
     public const PLACE_COMPLETE = 'complete';

    #[Place(info: 'Terminal error / exhausted retries')]
    public const PLACE_FAILED = 'failed';

    // ───────────── Transitions ─────────────

    #[Transition(
        from: [self::PLACE_NEW, self::PLACE_DOWNLOADED, self::PLACE_FAILED],
        to: self::PLACE_DOWNLOADED,
        info: 'Download',
        description: 'HTTP GET/stream to temp; detect MIME; set statusCode',
        async: true,
        // note: this goes here and NOT in place, because PlaceEntered is called BEFORE onCompleted
        next: [self::TRANSITION_DOWNLOAD_FAILED] # , self::TRANSITION_ARCHIVE]
    )]
    public const TRANSITION_DOWNLOAD = 'download';

    #[Transition(
        from: self::PLACE_DOWNLOADED,
        to: self::PLACE_FAILED,
        info: 'Download failed',
        description: 'Non-200 or I/O error; may be retried with backoff',
        guard: "subject.statusCode !== 200",
        async: false
    )]
    public const TRANSITION_DOWNLOAD_FAILED = 'download_failed';

//    #[Transition(
//        from: self::PLACE_DOWNLOADED,
//        to: self::PLACE_VALIDATED,
//        info: 'Validate',
//        description: 'Check type/codec/dimensions/size; normalize extension',
//        guard: "subject.statusCode === 200 and subject.mime matches '/^(image|audio|video)/'",
//        async: false,
//        next: [self::TRANSITION_ANALYZE]
//    )]
//    public const TRANSITION_VALIDATE = 'validate';
//
    #[Transition(
        from: self::PLACE_DOWNLOADED,
        to: self::PLACE_FAILED,
        info: 'Invalid file',
        description: 'Unsupported media type or invalid attributes',
        guard: "subject.statusCode === 200 and not (subject.mime matches '/^(image|audio|video)/')",
        async: false,
    )]
    public const TRANSITION_INVALID = 'invalid_file';

    #[Transition(
        from: self::PLACE_DOWNLOADED,
        to: self::PLACE_ANALYZED,
        info: 'Analyze',
        description: 'Compute blurhash/thumbhash, color palette, pHash, media probe',
        async: true,
        next: [self::TRANSITION_ARCHIVE]
    )]
    public const TRANSITION_ANALYZE = 'analyze';

     #[Transition(
         from: [self::PLACE_ANALYZED],
         to: self::PLACE_ARCHIVED,
         info: 'Archive original',
         description: 'Stub: archive original to durable storage (S3) and seed imgproxy cache',
         async: true
     )]
     public const TRANSITION_ARCHIVE = 'archive';

     #[Transition(
         from: self::PLACE_ARCHIVED,
         to: self::PLACE_COMPLETE,
         info: 'Finalize',
         description: 'Finalize asset (indexing handled by listeners)'
     )]
     public const TRANSITION_FINALIZE = 'finalize';
}
