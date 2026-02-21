<?php

namespace App\Controller\Admin;

use App\Entity\Media;
use App\Entity\Thumb;
use App\Entity\Variant;
use App\Workflow\ThumbFlowDefinition;
use App\Workflow\VariantFlowDefinition;
use App\Workflow\VariantWorkflow;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AvatarField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use Survos\StateBundle\Traits\EasyMarkingTrait;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;
use Survos\EzBundle\Controller\BaseCrudController;

class VariantCrudController extends BaseCrudController
{
    use EasyMarkingTrait;

    public function __construct(
        #[Target(VariantFlowDefinition::WORKFLOW_NAME)] protected WorkflowInterface $workflow
    )
    {
    }


    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add($this->markingFilter())
            ->add(TextFilter::new('asset'))

//            ->add(ChoiceFilter::new('preset')
//                ->setChoices(array_combine(Media::FILTERS, Media::FILTERS)))
            // too big!
//            ->add(EntityFilter::new('media'));

        ;

    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('asset');                 // Owner/user
        yield TextField::new('preset');
        yield TextField::new('marking');
        yield IntegerField::new('size');
        yield AvatarField::new('url')->setHeight(36);  // Visual thumbnail first
//        return [
//            IdField::new('id'),
//            TextField::new('title'),
//            TextEditorField::new('description'),
//        ];
    }

    public static function getEntityFqcn(): string
    {
        return Variant::class;
    }
}
