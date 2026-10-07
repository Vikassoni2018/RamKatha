<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\PronunciationRule;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PronunciationRuleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('sourceText', TextType::class, ['empty_data' => '', 'label' => 'Text as written', 'help' => 'e.g. श्रीकृष्ण'])
            ->add('replacementText', TextType::class, ['empty_data' => '', 'label' => 'Spoken as', 'required' => false, 'help' => 'e.g. श्री कृष्ण — leave empty to remove the text from narration.'])
            ->add('language', ChoiceType::class, ['label' => 'Language', 'choices' => ['Hindi (hi)' => 'hi', 'Sanskrit (sa)' => 'sa', 'English (en)' => 'en']])
            ->add('priority', IntegerType::class, ['label' => 'Priority', 'help' => 'Higher priority rules run first (then oldest first).'])
            ->add('wholeWord', CheckboxType::class, ['label' => 'Whole words only', 'required' => false])
            ->add('caseSensitive', CheckboxType::class, ['label' => 'Case sensitive (Latin text only)', 'required' => false])
            ->add('enabled', CheckboxType::class, ['label' => 'Enabled', 'required' => false])
            ->add('notes', TextareaType::class, ['label' => 'Notes', 'required' => false, 'attr' => ['rows' => 2]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PronunciationRule::class]);
    }
}
