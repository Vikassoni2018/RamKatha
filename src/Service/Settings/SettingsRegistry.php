<?php

declare(strict_types=1);

namespace App\Service\Settings;

/**
 * Single source of truth for every editable setting: type, default, group and validation.
 * Secrets (tokens, passwords, DSNs) are deliberately NOT settings — they come from ENV.
 */
final class SettingsRegistry
{
    public const SITE_NAME = 'site_name';
    public const SITE_TAGLINE = 'site_tagline';
    public const CONTACT_EMAIL = 'contact_email';
    public const LOGO_MEDIA = 'logo_media_id';
    public const FAVICON_MEDIA = 'favicon_media_id';
    public const DEFAULT_OG_MEDIA = 'default_og_media_id';
    public const SOCIAL_FACEBOOK = 'social_facebook_url';
    public const SOCIAL_YOUTUBE = 'social_youtube_url';
    public const SOCIAL_INSTAGRAM = 'social_instagram_url';
    public const SOCIAL_X = 'social_x_url';
    public const SOCIAL_WHATSAPP = 'social_whatsapp_url';

    public const SEO_TITLE_SUFFIX = 'seo_title_suffix';
    public const SEO_HOME_TITLE = 'seo_home_title';
    public const SEO_DEFAULT_DESCRIPTION = 'seo_default_description';

    public const CONTENT_DISCLAIMER = 'content_disclaimer';
    public const STORIES_PER_PAGE = 'stories_per_page';
    public const HOME_FEATURED_LIMIT = 'home_featured_limit';
    public const HOME_SECTION_LIMIT = 'home_section_limit';
    public const COMPLETION_THRESHOLD = 'story_completion_threshold';
    public const REGISTRATION_ENABLED = 'registration_enabled';
    public const PUBLIC_COLLECTIONS_ENABLED = 'public_collections_enabled';
    public const ANALYTICS_ENABLED = 'analytics_enabled';

    public const TTS_DEFAULT_VOICE = 'tts_default_voice';
    public const AUDIO_AUTO_GENERATE = 'audio_auto_generate_on_publish';
    public const AUDIO_MAX_ATTEMPTS = 'audio_max_attempts';

    public const DONATIONS_ENABLED = 'donations_enabled';
    public const UPI_ID = 'upi_id';
    public const UPI_PAYEE_NAME = 'upi_payee_name';
    public const DONATION_CURRENCY = 'donation_currency';
    public const DONATION_PRESETS = 'donation_preset_amounts';
    public const DONATION_MIN = 'donation_min_amount';
    public const DONATION_MAX = 'donation_max_amount';
    public const DONATION_MESSAGE = 'donation_message';
    public const DONATION_THANKS = 'donation_thanks_message';

    public const NOTIFY_EMAIL_FAILURES = 'notify_email_on_failures';

    /**
     * English variants of visitor-facing texts, used on /en pages: "<key>_en" of a Hindi text setting.
     * SettingsService::getLocalized() falls back to the Hindi value when the English one is empty.
     */
    public const SITE_NAME_EN = 'site_name_en';
    public const SITE_TAGLINE_EN = 'site_tagline_en';
    public const SEO_TITLE_SUFFIX_EN = 'seo_title_suffix_en';
    public const SEO_HOME_TITLE_EN = 'seo_home_title_en';
    public const SEO_DEFAULT_DESCRIPTION_EN = 'seo_default_description_en';
    public const CONTENT_DISCLAIMER_EN = 'content_disclaimer_en';
    public const DONATION_MESSAGE_EN = 'donation_message_en';
    public const DONATION_THANKS_EN = 'donation_thanks_message_en';

    public const GROUPS = [
        'general' => 'General',
        'seo' => 'SEO defaults',
        'content' => 'Content & reading',
        'audio' => 'Audio',
        'donations' => 'Voluntary support (UPI)',
        'notifications' => 'Notifications',
        'english' => 'English version (/en)',
    ];

    /** @var array<string, SettingDefinition>|null */
    private ?array $definitions = null;

    /** @return array<string, SettingDefinition> */
    public function all(): array
    {
        return $this->definitions ??= $this->build();
    }

    public function get(string $key): SettingDefinition
    {
        $all = $this->all();
        if (!isset($all[$key])) {
            throw new \InvalidArgumentException(\sprintf('Unknown setting "%s".', $key));
        }

        return $all[$key];
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /** @return array<string, list<SettingDefinition>> */
    public function byGroup(): array
    {
        $groups = [];
        foreach ($this->all() as $definition) {
            $groups[$definition->group][] = $definition;
        }

        return $groups;
    }

    /** @return array<string, SettingDefinition> */
    private function build(): array
    {
        $s = SettingDefinition::class;
        $defs = [
            // General
            new SettingDefinition(self::SITE_NAME, $s::TYPE_STRING, 'कथामृत', 'general', 'Site name', required: true),
            new SettingDefinition(self::SITE_TAGLINE, $s::TYPE_STRING, 'सनातन परंपरा की कथाएँ — स्रोत सहित पढ़ें और सुनें', 'general', 'Tagline'),
            new SettingDefinition(self::CONTACT_EMAIL, $s::TYPE_EMAIL, '', 'general', 'Contact email', 'Receives contact form messages and failure notifications.'),
            new SettingDefinition(self::LOGO_MEDIA, $s::TYPE_MEDIA, null, 'general', 'Logo image', 'Optional. A text logo is used when empty.'),
            new SettingDefinition(self::FAVICON_MEDIA, $s::TYPE_MEDIA, null, 'general', 'Favicon (PNG)', 'Optional. Defaults to the bundled icon.'),
            new SettingDefinition(self::DEFAULT_OG_MEDIA, $s::TYPE_MEDIA, null, 'general', 'Default social sharing image', 'Used when a story has no cover or OG image (1200×630 recommended).'),
            new SettingDefinition(self::SOCIAL_FACEBOOK, $s::TYPE_URL, '', 'general', 'Facebook page URL'),
            new SettingDefinition(self::SOCIAL_YOUTUBE, $s::TYPE_URL, '', 'general', 'YouTube channel URL'),
            new SettingDefinition(self::SOCIAL_INSTAGRAM, $s::TYPE_URL, '', 'general', 'Instagram URL'),
            new SettingDefinition(self::SOCIAL_X, $s::TYPE_URL, '', 'general', 'X (Twitter) URL'),
            new SettingDefinition(self::SOCIAL_WHATSAPP, $s::TYPE_URL, '', 'general', 'WhatsApp channel URL'),

            // SEO
            new SettingDefinition(self::SEO_TITLE_SUFFIX, $s::TYPE_STRING, ' | कथामृत', 'seo', 'Title suffix', 'Appended to page titles.'),
            new SettingDefinition(self::SEO_HOME_TITLE, $s::TYPE_STRING, 'कथामृत — हिंदी आध्यात्मिक कथाएँ पढ़ें और सुनें', 'seo', 'Homepage title'),
            new SettingDefinition(self::SEO_DEFAULT_DESCRIPTION, $s::TYPE_TEXT, 'भगवद्गीता, रामायण, महाभारत, पुराणों और संत परंपरा की कथाएँ — स्रोत संदर्भ सहित, हिंदी में पढ़ें और सुनें।', 'seo', 'Default meta description'),

            // Content
            new SettingDefinition(self::CONTENT_DISCLAIMER, $s::TYPE_TEXT, 'इस मंच की सामग्री में शास्त्रों के अनुवाद, रूपांतरण और पारंपरिक कथाएँ शामिल हैं। जहाँ उपलब्ध हैं, स्रोत संदर्भ दिए गए हैं। विभिन्न परंपराओं में व्याख्याएँ भिन्न हो सकती हैं। स्वैच्छिक सहयोग किसी आध्यात्मिक या भौतिक परिणाम की गारंटी नहीं है।', 'content', 'Content disclaimer (shown on story pages)'),
            new SettingDefinition(self::STORIES_PER_PAGE, $s::TYPE_INT, 18, 'content', 'Stories per listing page', min: 6, max: 60),
            new SettingDefinition(self::HOME_FEATURED_LIMIT, $s::TYPE_INT, 4, 'content', 'Homepage: featured stories', min: 1, max: 12),
            new SettingDefinition(self::HOME_SECTION_LIMIT, $s::TYPE_INT, 8, 'content', 'Homepage: stories per section', min: 3, max: 16),
            new SettingDefinition(self::COMPLETION_THRESHOLD, $s::TYPE_INT, 90, 'content', 'Listening completion threshold (%)', 'A story counts as completed once this share of the audio has been heard.', 50, 100),
            new SettingDefinition(self::REGISTRATION_ENABLED, $s::TYPE_BOOL, true, 'content', 'Allow new registrations'),
            new SettingDefinition(self::PUBLIC_COLLECTIONS_ENABLED, $s::TYPE_BOOL, true, 'content', 'Allow public collections'),
            new SettingDefinition(self::ANALYTICS_ENABLED, $s::TYPE_BOOL, true, 'content', 'Record first-party analytics', 'Anonymous, aggregated daily counters only.'),

            // Audio
            new SettingDefinition(self::TTS_DEFAULT_VOICE, $s::TYPE_STRING, '', 'audio', 'Default TTS voice', 'Overrides TTS_VOICE from the environment when set.'),
            new SettingDefinition(self::AUDIO_AUTO_GENERATE, $s::TYPE_BOOL, true, 'audio', 'Generate audio automatically on publish', 'When a story is published without audio and TTS is configured, narration is queued automatically.'),
            new SettingDefinition(self::AUDIO_MAX_ATTEMPTS, $s::TYPE_INT, 3, 'audio', 'Max automatic audio attempts', min: 1, max: 10),

            // Donations
            new SettingDefinition(self::DONATIONS_ENABLED, $s::TYPE_BOOL, true, 'donations', 'Enable voluntary support'),
            new SettingDefinition(self::UPI_ID, $s::TYPE_STRING, '', 'donations', 'UPI ID (VPA)', 'e.g. trustname@bank. Falls back to UPI_ID from the environment.'),
            new SettingDefinition(self::UPI_PAYEE_NAME, $s::TYPE_STRING, '', 'donations', 'Payee name', 'Shown in the UPI app. Falls back to UPI_PAYEE_NAME.'),
            new SettingDefinition(self::DONATION_CURRENCY, $s::TYPE_CHOICE, 'INR', 'donations', 'Currency', 'UPI supports INR only.', choices: ['INR (₹)' => 'INR']),
            new SettingDefinition(self::DONATION_PRESETS, $s::TYPE_INT_LIST, [51, 101, 251, 501], 'donations', 'Preset amounts (₹, comma separated)', min: 1, max: 1000000),
            new SettingDefinition(self::DONATION_MIN, $s::TYPE_INT, 1, 'donations', 'Minimum amount (₹)', min: 1, max: 100000),
            new SettingDefinition(self::DONATION_MAX, $s::TYPE_INT, 100000, 'donations', 'Maximum amount (₹)', min: 1, max: 10000000),
            new SettingDefinition(self::DONATION_MESSAGE, $s::TYPE_TEXT, 'यदि यह कथा आपके लिए उपयोगी रही हो, तो आप स्वेच्छा से इस ज्ञान-सेवा को सहयोग दे सकते हैं। आपका सहयोग कथाओं को निःशुल्क उपलब्ध रखने, ऑडियो वर्णन, ग्रंथों के डिजिटलीकरण और मंच के रखरखाव में सहायक होता है। सहयोग पूर्णतः ऐच्छिक है — सभी कथाएँ सबके लिए निःशुल्क हैं और रहेंगी।', 'donations', 'Support message'),
            new SettingDefinition(self::DONATION_THANKS, $s::TYPE_TEXT, 'आपके स्वैच्छिक सहयोग के लिए हार्दिक धन्यवाद। भुगतान की पुष्टि हमारी टीम द्वारा बैंक विवरण से मिलान के बाद की जाती है।', 'donations', 'Thank-you message'),

            // Notifications
            new SettingDefinition(self::NOTIFY_EMAIL_FAILURES, $s::TYPE_BOOL, true, 'notifications', 'Email failures to the contact address', 'Audio and Facebook failures (requires mail to be configured).'),
            // English version (empty = use the Hindi text on English pages too)
            new SettingDefinition(self::SITE_NAME_EN, $s::TYPE_STRING, 'Kathamrit', 'english', 'Site name (English)', 'Shown on English pages. Leave empty to use the Hindi name.'),
            new SettingDefinition(self::SITE_TAGLINE_EN, $s::TYPE_STRING, 'Stories of the Sanatan tradition — read and listen, with sources', 'english', 'Tagline (English)'),
            new SettingDefinition(self::SEO_TITLE_SUFFIX_EN, $s::TYPE_STRING, ' | Kathamrit', 'english', 'Title suffix (English)', 'Appended to page titles on English pages.'),
            new SettingDefinition(self::SEO_HOME_TITLE_EN, $s::TYPE_STRING, 'Kathamrit — Read and listen to stories of the Sanatan tradition', 'english', 'Homepage title (English)'),
            new SettingDefinition(self::SEO_DEFAULT_DESCRIPTION_EN, $s::TYPE_TEXT, 'Stories from the Bhagavad Gita, the Ramayana, the Mahabharata, the Puranas and the saint traditions — with source references, to read and to listen to.', 'english', 'Default meta description (English)'),
            new SettingDefinition(self::CONTENT_DISCLAIMER_EN, $s::TYPE_TEXT, 'Content on this platform includes translations and adaptations of scriptures as well as traditional stories. Source references are given where available. Interpretations may differ between traditions. Voluntary support does not guarantee any spiritual or material outcome.', 'english', 'Content disclaimer (English)'),
            new SettingDefinition(self::DONATION_MESSAGE_EN, $s::TYPE_TEXT, 'If this story has been useful to you, you may voluntarily support this service of sharing knowledge. Your support helps keep the stories freely available and pays for audio narration, the digitisation of texts and the upkeep of the platform. Support is entirely optional — every story is and will remain free for everyone.', 'english', 'Support message (English)'),
            new SettingDefinition(self::DONATION_THANKS_EN, $s::TYPE_TEXT, 'Thank you sincerely for your voluntary support. Our team confirms each payment after matching it with the bank statement.', 'english', 'Thank-you message (English)'),
        ];

        $indexed = [];
        foreach ($defs as $def) {
            $indexed[$def->key] = $def;
        }

        return $indexed;
    }
}
