<?php
declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Variant;
use Survos\StateBundle\Attribute\Place;
use Survos\StateBundle\Attribute\Transition;
use Survos\StateBundle\Attribute\Workflow;

/**
 * Minimal state machine for generating/storing a single variant.
 * - Auto-kicks "resize" from NEW using Place(next: ...).
 * - The "resize" transition is async and should be handled by your worker/transport.
 */
#[Workflow(
    name: self::WORKFLOW_NAME,
    type: 'state_machine',
    supports: [Variant::class],
)]
final class VariantFlowDefinition
{
    public const WORKFLOW_NAME = 'VariantWorkflow';

    #[Place(initial: true, next: [self::TRANSITION_RESIZE])]
    public const PLACE_NEW   = 'new';

    #[Place]
    public const PLACE_DONE  = 'done';

    #[Place]
    public const PLACE_ERROR = 'error';

    /**
     * Generate/encode/store the variant for this preset/format.
     * Async so it goes through Messenger (set transport to your queue name).
     */
    #[Transition(
        from: [self::PLACE_NEW],
        to: self::PLACE_DONE,
        async: true
    )]
    public const TRANSITION_RESIZE = 'resize';

    /**
     * Allow retries to re-run the async generator if something failed upstream.
     * (You can dispatch this manually or via an error listener.)
     */
    #[Transition(
        from: [self::PLACE_ERROR],
        to: self::PLACE_DONE,
        async: true
    )]
    public const TRANSITION_RETRY = 'retry';
}
