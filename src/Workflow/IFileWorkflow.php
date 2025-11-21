<?php

namespace App\Workflow;

use App\Entity\File;
use Survos\StateBundle\Attribute\Place;
use Survos\StateBundle\Attribute\Transition;
use Survos\StateBundle\Attribute\Workflow;

#[Workflow(supports: [File::class], name: self::WORKFLOW_NAME)]
class IFileWorkflow
{
	public const WORKFLOW_NAME = 'FileWorkflow';

    #[Place(next: [self::TRANSITION_LIST])]
	public const PLACE_NEW_DIR = 'new_dir';

    #[Place(initial: true)]
    public const PLACE_NEW_FILE = 'new_file';

	#[Place]
	public const PLACE_LISTING = 'dir_has_listing';

	#[Place]
	public const PLACE_DOWNLOADED = 'downloaded';

    #[Place]
    public const PLACE_META = 'file_has_meta';

    // @todo: move guard here.
	#[Transition(from: [self::PLACE_NEW_DIR], to: self::PLACE_LISTING, async: true)]
	public const TRANSITION_LIST = 'list';

	#[Transition(from: [self::PLACE_NEW_FILE], to: self::PLACE_DOWNLOADED)]
	public const TRANSITION_DOWNLOAD = 'download';

    #[Transition(from: [self::PLACE_NEW_FILE], to: self::PLACE_META, async: true, info: "Use s3 client to fetch metadata")]
    public const TRANSITION_META = 'meta';
}
