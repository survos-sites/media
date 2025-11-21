<?php

namespace App\Form;

use Doctrine\DBAL\Types\BooleanType;
use Survos\SaisBundle\Model\ProcessPayload;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProcessPayloadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('root', TextType::class, [
                'help' => 'root for file storage.',
                'required' => false,
            ])
            ->add('wait', CheckboxType::class, [
                'required' => false,
            ])
//            ->add('apiKey', TextType::class, [
//                'help' => 'api kep.',
//                'required' => false,
//            ])
            ->add('images', TextareaType::class, [
                'attr' => [
                    'cols' => 80,
                    'rows' => 10,
                ]

            ])->add('mediaCallbackUrl', UrlType::class, [
                'default_protocol' => 'http',
                'help' => 'callback url for media download',
                'required' => false,
            ])->add('thumbCallbackUrl', UrlType::class, [
                'default_protocol' => 'http',
                'help' => 'callback url for thumbnail generation',
                'required' => false,
            ]);

        $builder->get('images')
            ->addModelTransformer(new CallbackTransformer(
                fn ($tagsAsString): string => json_encode($tagsAsString, JSON_UNESCAPED_SLASHES + JSON_PRETTY_PRINT + JSON_UNESCAPED_UNICODE),
                fn ($tagsAsArray): array => json_decode($tagsAsArray, true),
            ))
        ;

    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProcessPayload::class,
        ]);
    }
}
