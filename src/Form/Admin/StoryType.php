<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\Story;
use App\Entity\Taxonomy\Category;
use App\Entity\Taxonomy\Deity;
use App\Entity\Taxonomy\Festival;
use App\Entity\Taxonomy\Scripture;
use App\Entity\Taxonomy\Series;
use App\Entity\Taxonomy\Tag;
use App\Enum\SourceType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The story editor. Workflow fields (status, verification) are NOT part of the form: they change
 * only through StoryWorkflowService actions. Privileged fields are shown only to editors.
 */
final class StoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $orderByPosition = static fn ($repo) => $repo->createQueryBuilder('t')->orderBy('t.position')->addOrderBy('t.name');

        $builder
            // Content
            ->add('title', TextType::class, ['empty_data' => '', 'label' => 'Title (Hindi)', 'attr' => ['maxlength' => 255, 'data-slug-target' => 'source', 'data-action' => 'input->slug#suggest']])
            ->add('originalTitle', TextType::class, ['label' => 'Original title', 'required' => false, 'help' => 'e.g. the Sanskrit title or the name used in the source.'])
            ->add('slug', TextType::class, ['empty_data' => '', 'label' => 'URL slug', 'required' => false, 'help' => 'Leave empty to generate from the title. Changing it on a published story keeps a 301 redirect from the old URL.', 'attr' => ['data-slug-target' => 'slug', 'pattern' => '[a-z0-9\-]*']])
            ->add('subtitle', TextType::class, ['label' => 'Subtitle', 'required' => false])
            ->add('excerpt', TextareaType::class, ['empty_data' => '', 'label' => 'Excerpt', 'help' => 'Shown on cards and used as default meta description (max 600 characters).', 'attr' => ['rows' => 3, 'maxlength' => 600]])
            ->add('body', TextareaType::class, ['empty_data' => '', 'label' => 'Story text', 'attr' => ['rows' => 18, 'data-rich-editor-target' => 'input']])
            ->add('language', ChoiceType::class, ['label' => 'Language', 'choices' => ['हिन्दी (hi)' => 'hi', 'संस्कृत (sa)' => 'sa', 'English (en)' => 'en']])

            // Taxonomy
            ->add('categories', EntityType::class, ['class' => Category::class, 'choice_label' => 'name', 'multiple' => true, 'expanded' => true, 'required' => false, 'label' => 'Categories', 'query_builder' => $orderByPosition])
            ->add('deities', EntityType::class, ['class' => Deity::class, 'choice_label' => 'name', 'multiple' => true, 'expanded' => true, 'required' => false, 'label' => 'Deities', 'query_builder' => $orderByPosition])
            ->add('festivals', EntityType::class, ['class' => Festival::class, 'choice_label' => 'name', 'multiple' => true, 'expanded' => true, 'required' => false, 'label' => 'Festivals', 'query_builder' => $orderByPosition])
            ->add('tags', EntityType::class, ['class' => Tag::class, 'choice_label' => 'name', 'multiple' => true, 'required' => false, 'label' => 'Tags', 'attr' => ['size' => 6], 'query_builder' => static fn ($repo) => $repo->createQueryBuilder('t')->orderBy('t.name')])
            ->add('newTags', TextType::class, ['mapped' => false, 'required' => false, 'label' => 'Add new tags', 'help' => 'Comma separated, e.g. भक्ति, त्याग', 'constraints' => [new Assert\Length(max: 500)]])
            ->add('scripture', EntityType::class, ['class' => Scripture::class, 'choice_label' => 'name', 'required' => false, 'placeholder' => '— None —', 'label' => 'Primary scripture (granth)', 'query_builder' => $orderByPosition])
            ->add('series', EntityType::class, ['class' => Series::class, 'choice_label' => 'name', 'required' => false, 'placeholder' => '— Not part of a series —', 'label' => 'Series', 'query_builder' => $orderByPosition])
            ->add('seriesPosition', IntegerType::class, ['label' => 'Position in series', 'required' => false, 'help' => 'Empty = append at the end.', 'attr' => ['min' => 0]])

            // Sources
            ->add('sourceType', EnumType::class, ['class' => SourceType::class, 'label' => 'Source classification', 'choice_label' => static fn (SourceType $t) => $t->label().' — '.$t->hindiLabel(), 'help' => 'Be precise: “Direct Scripture Translation” only for actual translations of the text.'])
            ->add('sourceReferences', CollectionType::class, [
                'entry_type' => StorySourceReferenceType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => 'Source references',
                'attr' => ['data-add-label' => 'Add source reference', 'data-entry-label' => 'Source reference'],
            ])
            ->add('sourceNotes', TextareaType::class, ['label' => 'Source notes (public)', 'required' => false, 'attr' => ['rows' => 3]])

            // Media
            ->add('coverImage', MediaPickerType::class, ['label' => 'Cover image'])
            ->add('ogImage', MediaPickerType::class, ['label' => 'Social sharing image (optional)', 'help' => 'Falls back to the cover, then the site default. 1200×630 recommended.'])

            // Audio
            ->add('audioEnabled', CheckboxType::class, ['label' => 'Narration enabled for this story', 'required' => false])
            ->add('ttsVoice', TextType::class, ['label' => 'Voice override', 'required' => false, 'help' => 'Leave empty to use the default voice.'])

            // SEO
            ->add('seoTitle', TextType::class, ['label' => 'SEO title', 'required' => false, 'attr' => ['data-serp-preview-target' => 'title', 'data-action' => 'input->serp-preview#update']])
            ->add('seoDescription', TextareaType::class, ['label' => 'Meta description', 'required' => false, 'attr' => ['rows' => 2, 'data-serp-preview-target' => 'description', 'data-action' => 'input->serp-preview#update']])
            ->add('canonicalUrl', UrlType::class, ['label' => 'Canonical URL override', 'required' => false, 'default_protocol' => 'https', 'help' => 'Only if this text is primarily published elsewhere.'])

            // Social
            ->add('facebookTitle', TextType::class, ['label' => 'Facebook title', 'required' => false])
            ->add('facebookDescription', TextareaType::class, ['label' => 'Facebook description', 'required' => false, 'attr' => ['rows' => 2]])

            // Support CTA
            ->add('showDonationCta', CheckboxType::class, ['label' => 'Show the voluntary support card on this story', 'required' => false])
            ->add('donationMessage', TextareaType::class, ['label' => 'Story-specific support message', 'required' => false, 'attr' => ['rows' => 2], 'help' => 'Optional. Keep it voluntary; never promise spiritual or material outcomes.'])

            // Publishing (date is used by the "Schedule" action)
            ->add('scheduledAt', DateTimeType::class, [
                'label' => 'Planned publication date',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'model_timezone' => 'UTC',
                'view_timezone' => $options['timezone'],
                'help' => 'Used by the “Schedule” action (time zone: '.$options['timezone'].').',
            ]);

        if ($options['is_editor']) {
            $builder
                ->add('featured', CheckboxType::class, ['label' => 'Featured on the homepage', 'required' => false])
                ->add('robotsIndex', CheckboxType::class, ['label' => 'Allow search engines to index', 'required' => false])
                ->add('robotsFollow', CheckboxType::class, ['label' => 'Allow search engines to follow links', 'required' => false])
                ->add('autoPublishFacebook', CheckboxType::class, ['label' => 'Post to Facebook automatically when published', 'required' => false]);
        }

        $builder->add('changeSummary', TextType::class, ['mapped' => false, 'required' => false, 'label' => 'Change summary (for the revision history)', 'constraints' => [new Assert\Length(max: 255)]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Story::class,
            'is_editor' => false,
            'timezone' => 'Asia/Kolkata',
        ]);
        $resolver->setAllowedTypes('is_editor', 'bool');
        $resolver->setAllowedTypes('timezone', 'string');
    }
}
