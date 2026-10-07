<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Service\Audio\AudioChunker;
use App\Service\Audio\AudioTextPreprocessor;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Admin tool (LiveComponent): type any text and instantly see what the TTS engine will receive
 * after punctuation normalisation and the pronunciation dictionary — plus how it will be chunked.
 */
#[AsLiveComponent('PronunciationPreview', template: 'components/PronunciationPreview.html.twig')]
final class PronunciationPreview
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public string $text = 'श्रीकृष्ण ने गोवर्धन पर्वत उठाया॥ ॐ नमः शिवाय।';

    #[LiveProp(writable: true)]
    public string $language = 'hi';

    public function __construct(
        private readonly AudioTextPreprocessor $preprocessor,
        private readonly AudioChunker $chunker,
    ) {
    }

    public function getProcessed(): string
    {
        return $this->preprocessor->prepare(mb_substr($this->text, 0, 5000), \in_array($this->language, ['hi', 'sa', 'en'], true) ? $this->language : 'hi');
    }

    /** @return list<string> */
    public function getChunks(): array
    {
        return $this->chunker->split($this->getProcessed(), 300);
    }
}
