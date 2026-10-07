<?php

declare(strict_types=1);

namespace App\Service\Audio;

use App\Entity\PronunciationRule;
use App\Repository\PronunciationRuleRepository;

/**
 * Applies the admin-maintained pronunciation dictionary before narration.
 * Order is deterministic: priority DESC, then id ASC (see repository).
 */
final class PronunciationService
{
    /** Letters, combining marks (Devanagari matras/virama) and digits form a "word". */
    private const WORD_CHARS = '\p{L}\p{M}\p{N}';

    public function __construct(private readonly PronunciationRuleRepository $repository)
    {
    }

    public function apply(string $text, string $language): string
    {
        return $this->applyRules($text, $this->repository->findEnabledForLanguage($this->baseLanguage($language)));
    }

    /**
     * @param iterable<PronunciationRule> $rules already sorted
     */
    public function applyRules(string $text, iterable $rules): string
    {
        foreach ($rules as $rule) {
            if (!$rule->isEnabled() || '' === $rule->getSourceText()) {
                continue;
            }
            $text = $this->applyRule($text, $rule);
        }

        return $text;
    }

    public function applyRule(string $text, PronunciationRule $rule): string
    {
        $source = $rule->getSourceText();
        $replacement = $rule->getReplacementText();

        if (!$rule->isWholeWord() && $rule->isCaseSensitive()) {
            return str_replace($source, $replacement, $text);
        }

        $pattern = preg_quote($source, '/');
        if ($rule->isWholeWord()) {
            // \b is unreliable for Devanagari (matras are combining marks), so use explicit look-arounds.
            $pattern = '(?<!['.self::WORD_CHARS.'])'.$pattern.'(?!['.self::WORD_CHARS.'])';
        }
        $flags = 'u'.($rule->isCaseSensitive() ? '' : 'i');

        // Escape "$" and "\" so the replacement is always literal.
        $result = preg_replace('/'.$pattern.'/'.$flags, addcslashes($replacement, '\\$'), $text);

        return $result ?? $text;
    }

    private function baseLanguage(string $language): string
    {
        return strtolower(explode('-', str_replace('_', '-', $language))[0]);
    }
}
