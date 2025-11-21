<?php

namespace App\Controller\Admin;

use App\Workflow\IFileWorkflow;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use Survos\EzBundle\Controller\BaseCrudController;
use Survos\EzBundle\Service\EzService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Workflow\WorkflowInterface;

class FileCrudController extends BaseCrudController
{
    public function __construct(
        protected UrlGeneratorInterface $urlGenerator,
        #[Autowire(service: EzService::class)] private readonly EzService $ez,

        #[Target(IFileWorkflow::WORKFLOW_NAME)] private readonly WorkflowInterface $workflow,
    )
    {
        parent::__construct($this->urlGenerator, $this->ez);
    }

    public static function getEntityFqcn(): string
    {
        return \App\Entity\File::class;
    }

    public function configureFields(string $pageName): iterable
    {

        yield ChoiceField::new('marking')->setChoices(
            $this->workflow->getDefinition()->getPlaces()
        );
        foreach (parent::configureFields($pageName) as $field) {
            yield $field;
        }
    }
}
