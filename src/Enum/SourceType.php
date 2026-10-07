<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How a story relates to its source. Shown publicly so readers can judge authenticity.
 * Never auto-assign DirectTranslation: it must be chosen deliberately by an editor.
 */
enum SourceType: string
{
    case DirectTranslation = 'direct_translation';
    case ScriptureAdaptation = 'scripture_adaptation';
    case TraditionalNarrative = 'traditional_narrative';
    case FolkTradition = 'folk_tradition';
    case Commentary = 'commentary';
    case OriginalEditorial = 'original_editorial';

    public function label(): string
    {
        return match ($this) {
            self::DirectTranslation => 'Direct Scripture Translation',
            self::ScriptureAdaptation => 'Scripture Adaptation',
            self::TraditionalNarrative => 'Traditional Narrative',
            self::FolkTradition => 'Folk Tradition',
            self::Commentary => 'Commentary',
            self::OriginalEditorial => 'Original Editorial Content',
        };
    }

    public function hindiLabel(): string
    {
        return match ($this) {
            self::DirectTranslation => 'शास्त्र का प्रत्यक्ष अनुवाद',
            self::ScriptureAdaptation => 'शास्त्र पर आधारित रूपांतरण',
            self::TraditionalNarrative => 'पारंपरिक कथा',
            self::FolkTradition => 'लोक परंपरा',
            self::Commentary => 'व्याख्या / टीका',
            self::OriginalEditorial => 'मौलिक संपादकीय सामग्री',
        };
    }

    public function hindiDescription(): string
    {
        return match ($this) {
            self::DirectTranslation => 'यह मूल ग्रंथ के पाठ का अनुवाद है।',
            self::ScriptureAdaptation => 'यह कथा मूल ग्रंथ पर आधारित है, परंतु सरल भाषा में पुनः प्रस्तुत की गई है।',
            self::TraditionalNarrative => 'यह कथा परंपरा से कही-सुनी जाती रही है; विभिन्न स्रोतों में इसके रूप भिन्न हो सकते हैं।',
            self::FolkTradition => 'यह लोक परंपरा में प्रचलित कथा है; इसका किसी एक शास्त्र में उल्लेख आवश्यक नहीं है।',
            self::Commentary => 'यह किसी ग्रंथ या प्रसंग की व्याख्या है।',
            self::OriginalEditorial => 'यह हमारी संपादकीय टीम द्वारा लिखी गई सामग्री है।',
        };
    }

    /** Whether the classification claims a textual scripture basis (requires references). */
    public function requiresReference(): bool
    {
        return \in_array($this, [self::DirectTranslation, self::ScriptureAdaptation, self::Commentary], true);
    }

    /** @return array<string, self> */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->label().' — '.$case->hindiLabel()] = $case;
        }

        return $choices;
    }
}
