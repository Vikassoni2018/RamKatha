<?php

declare(strict_types=1);

namespace App\Tests\Integration\Social;

use App\Entity\Story;
use App\Service\Social\SocialCaptionRenderer;
use App\Tests\Integration\IntegrationTestCase;

final class SocialCaptionRendererTest extends IntegrationTestCase
{
    private const TEMPLATE = "{{story_title}}\n\n{{story_excerpt}}\n\nस्रोत: {{source_name}}\n\nपढ़ें और सुनें: {{story_url}}";

    public function testRendersPlaceholdersFromTheStoryOnly(): void
    {
        $story = $this->story('makhan-chor-bal-krishna');
        $caption = $this->service(SocialCaptionRenderer::class)->render(self::TEMPLATE, $story, '#Krishna #Katha');

        self::assertStringStartsWith($story->getFacebookTitle() ?: $story->getTitle(), $caption);
        self::assertStringContainsString('पढ़ें और सुनें: http://localhost/katha/makhan-chor-bal-krishna', $caption);
        self::assertStringEndsWith('#Krishna #Katha', $caption, 'Hashtags are appended when the template has no placeholder.');
        self::assertStringNotContainsString('{{', $caption);
    }

    public function testEmptyLabelledLinesAreDropped(): void
    {
        $story = (new Story())->setTitle('डेमो')->setSlug('demo-caption')->setExcerpt('सार');
        $caption = $this->service(SocialCaptionRenderer::class)->render(self::TEMPLATE, $story);

        self::assertStringNotContainsString('स्रोत:', $caption, 'No dangling "source:" label when there is no source.');
        self::assertStringNotContainsString("\n\n\n", $caption);
    }
}
