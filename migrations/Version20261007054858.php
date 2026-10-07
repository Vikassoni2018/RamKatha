<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * English version of the public site (/en): optional English texts next to the Hindi originals.
 *
 * - story: title/subtitle/excerpt/body/source notes/SEO/support message + derived English word count
 *   and reading time; the existing FULLTEXT search_text document now also carries the English text
 * - taxonomies: English description and SEO fields (nameEn already existed); scripture attribution
 *   and original language, festival period
 * - page: English title/body/SEO; source references: English note; revisions: English snapshot
 * All columns are nullable/defaulted, so existing rows stay valid (English pages fall back to Hindi).
 */
final class Version20261007054858 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'English version of the public site: optional English content fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category ADD description_en LONGTEXT DEFAULT NULL, ADD seo_title_en VARCHAR(255) DEFAULT NULL, ADD seo_description_en VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE deity ADD description_en LONGTEXT DEFAULT NULL, ADD seo_title_en VARCHAR(255) DEFAULT NULL, ADD seo_description_en VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE festival ADD description_en LONGTEXT DEFAULT NULL, ADD seo_title_en VARCHAR(255) DEFAULT NULL, ADD seo_description_en VARCHAR(500) DEFAULT NULL, ADD typical_period_en VARCHAR(150) DEFAULT NULL');
        $this->addSql('ALTER TABLE page ADD title_en VARCHAR(255) DEFAULT NULL, ADD body_en MEDIUMTEXT DEFAULT NULL, ADD seo_title_en VARCHAR(255) DEFAULT NULL, ADD seo_description_en VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE scripture ADD description_en LONGTEXT DEFAULT NULL, ADD seo_title_en VARCHAR(255) DEFAULT NULL, ADD seo_description_en VARCHAR(500) DEFAULT NULL, ADD attributed_to_en VARCHAR(150) DEFAULT NULL, ADD original_language_en VARCHAR(80) DEFAULT NULL');
        $this->addSql('ALTER TABLE series ADD description_en LONGTEXT DEFAULT NULL, ADD seo_title_en VARCHAR(255) DEFAULT NULL, ADD seo_description_en VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE story ADD title_en VARCHAR(255) DEFAULT NULL, ADD subtitle_en VARCHAR(255) DEFAULT NULL, ADD excerpt_en LONGTEXT DEFAULT NULL, ADD body_en MEDIUMTEXT DEFAULT NULL, ADD source_notes_en LONGTEXT DEFAULT NULL, ADD seo_title_en VARCHAR(255) DEFAULT NULL, ADD seo_description_en VARCHAR(500) DEFAULT NULL, ADD donation_message_en LONGTEXT DEFAULT NULL, ADD word_count_en INT DEFAULT 0 NOT NULL, ADD reading_time_minutes_en INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE story_revision ADD english_snapshot JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE story_source_reference ADD notes_en LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE tag ADD description_en LONGTEXT DEFAULT NULL, ADD seo_title_en VARCHAR(255) DEFAULT NULL, ADD seo_description_en VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category DROP description_en, DROP seo_title_en, DROP seo_description_en');
        $this->addSql('ALTER TABLE deity DROP description_en, DROP seo_title_en, DROP seo_description_en');
        $this->addSql('ALTER TABLE festival DROP description_en, DROP seo_title_en, DROP seo_description_en, DROP typical_period_en');
        $this->addSql('ALTER TABLE page DROP title_en, DROP body_en, DROP seo_title_en, DROP seo_description_en');
        $this->addSql('ALTER TABLE scripture DROP description_en, DROP seo_title_en, DROP seo_description_en, DROP attributed_to_en, DROP original_language_en');
        $this->addSql('ALTER TABLE series DROP description_en, DROP seo_title_en, DROP seo_description_en');
        $this->addSql('ALTER TABLE story DROP title_en, DROP subtitle_en, DROP excerpt_en, DROP body_en, DROP source_notes_en, DROP seo_title_en, DROP seo_description_en, DROP donation_message_en, DROP word_count_en, DROP reading_time_minutes_en');
        $this->addSql('ALTER TABLE story_revision DROP english_snapshot');
        $this->addSql('ALTER TABLE story_source_reference DROP notes_en');
        $this->addSql('ALTER TABLE tag DROP description_en, DROP seo_title_en, DROP seo_description_en');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
