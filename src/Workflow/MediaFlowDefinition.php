<?php

namespace App\Workflow;

use App\Entity\Media;
use Survos\StateBundle\Attribute\Place;
use Survos\StateBundle\Attribute\Transition;
use Survos\StateBundle\Attribute\Workflow;

#[Workflow(supports: [Media::class], name: self::WORKFLOW_NAME)]
class MediaFlowDefinition
{
	public const WORKFLOW_NAME = 'MediaWorkflow';

	#[Place(initial: true)]
	public const PLACE_NEW = 'new';

	#[Place]
	public const PLACE_DOWNLOADED = 'downloaded';
	public const PLACE_FAILED = 'failed';

	#[Place(next: [self::TRANSITION_ARCHIVE])]
	public const PLACE_RESIZED = 'resized';

    #[Place]
    public const PLACE_ARCHIVED = 'archived';

	#[Transition(
        from: [self::PLACE_NEW, self::PLACE_DOWNLOADED, self::PLACE_FAILED],
        to: self::PLACE_DOWNLOADED,
        info: "Download originalUrl to /tmp",
        async: true
    )]
	public const TRANSITION_DOWNLOAD = 'download';

    #[Transition(from: [self::PLACE_DOWNLOADED], to: self::PLACE_FAILED)]
    public const TRANSITION_DOWNLOAD_FAILED = 'download_failed';

    #[Transition(from: [self::PLACE_DOWNLOADED], to: self::PLACE_FAILED)]
    public const TRANSITION_INVALID = 'invalid_file';

	#[Transition(from: [self::PLACE_DOWNLOADED, self::PLACE_RESIZED], to: self::PLACE_RESIZED)]
	public const TRANSITION_RESIZE = 'resize';

    #[Transition(from: [self::PLACE_DOWNLOADED, self::PLACE_RESIZED], to: self::PLACE_ARCHIVED, async: true)]
    public const TRANSITION_ARCHIVE = 'archive';

    #[Transition(from: [self::PLACE_RESIZED], to: self::PLACE_RESIZED, async: true)]
    public const TRANSITION_REFRESH = 'refresh';

}
