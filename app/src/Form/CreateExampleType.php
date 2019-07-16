<?php

declare(strict_types=1);

namespace App\Form;

use App\Request\CreateExampleRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class CreateExampleType extends AbstractType
{
    /**
     * @var UrlGeneratorInterface
     */
    private $urlGenerator;

    public function __construct(UrlGeneratorInterface $urlGenerator)
    {
        $this->urlGenerator = $urlGenerator;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextareaType::class)
            ->add('test', TextareaType::class)
            ->add('config', TextareaType::class)
            ->add('mutate', SubmitType::class, ['label' => 'Mutate']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CreateExampleRequest::class,
            'action' => $this->urlGenerator->generate('playground_create_example'),
        ]);
    }
}
