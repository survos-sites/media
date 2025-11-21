<?php

namespace App\Form;

use Survos\SaisBundle\Model\AccountSetup;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AccountSetupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('root')
            ->add('approx')
            ->add('mediaCallbackUrl')
            ->add('thumbCallbackUrl')
            ->add('apiKey')
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AccountSetup::class,
        ]);
    }
}
