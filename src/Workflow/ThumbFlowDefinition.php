<?php

namespace App\Workflow;

use App\Entity\Thumb;
use Survos\StateBundle\Attribute\Place;
use Survos\StateBundle\Attribute\Transition;
use Survos\StateBundle\Attribute\Workflow;

#[Workflow(supports: [Thumb::class], name: self::WORKFLOW_NAME)]
class ThumbFlowDefinition
{
	public const WORKFLOW_NAME = 'ThumbWorkflow';

	#[Place(initial: true)]
	public const PLACE_NEW = 'new';

	#[Place]
	public const PLACE_DONE = 'done';

	#[Transition(from: [self::PLACE_NEW, self::PLACE_DONE], to: self::PLACE_DONE,  transport: 'resize',)]
	public const TRANSITION_RESIZE = 'resize';
}
