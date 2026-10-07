<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial schema of the Katha platform (MySQL 8+ / MariaDB 10.4+).
 *
 * - utf8mb4 / utf8mb4_unicode_ci everywhere (Devanagari safe)
 * - FULLTEXT indexes on story (title/subtitle/original_title and title/subtitle/excerpt/search_text)
 * - messenger_messages table for the Doctrine Messenger transport (no Redis/RabbitMQ)
 * - Composite indexes follow the real query patterns documented on each entity
 */
final class Version20261005110420 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: users, stories, taxonomy, audio, collections, donations, social, analytics, audit, settings, messenger';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE account_token (id INT AUTO_INCREMENT NOT NULL, used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, type VARCHAR(32) NOT NULL, selector VARCHAR(32) NOT NULL, verifier_hash VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX idx_account_token_user_type (user_id, type), INDEX idx_account_token_expires (expires_at), UNIQUE INDEX uniq_account_token_selector (selector), INDEX IDX_69B8784AA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE admin_notification (id INT AUTO_INCREMENT NOT NULL, level VARCHAR(20) NOT NULL, read_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, type VARCHAR(40) NOT NULL, title VARCHAR(255) NOT NULL, message LONGTEXT DEFAULT NULL, link VARCHAR(500) DEFAULT NULL, INDEX idx_notification_read_created (read_at, created_at), INDEX idx_notification_level_created (level, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE app_setting (id INT AUTO_INCREMENT NOT NULL, value LONGTEXT NOT NULL, updated_at DATETIME NOT NULL, name VARCHAR(100) NOT NULL, updated_by_id INT DEFAULT NULL, UNIQUE INDEX uniq_setting_name (name), INDEX IDX_722938D5896DBBDE (updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE app_user (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, display_name VARCHAR(100) NOT NULL, is_verified TINYINT DEFAULT 0 NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, last_login_at DATETIME DEFAULT NULL, deactivated_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_user_created (created_at), UNIQUE INDEX uniq_user_email (email), UNIQUE INDEX uniq_user_uuid (uuid), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE audio_asset (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, language VARCHAR(10) NOT NULL, voice VARCHAR(100) DEFAULT NULL, provider VARCHAR(50) NOT NULL, storage_key VARCHAR(255) DEFAULT NULL, file_name VARCHAR(255) DEFAULT NULL, mime_type VARCHAR(50) DEFAULT NULL, file_size INT DEFAULT NULL, duration_seconds INT DEFAULT NULL, checksum VARCHAR(64) DEFAULT NULL, text_checksum VARCHAR(64) DEFAULT NULL, chunk_count INT DEFAULT NULL, status VARCHAR(20) NOT NULL, attempts INT DEFAULT 0 NOT NULL, last_attempt_at DATETIME DEFAULT NULL, generation_started_at DATETIME DEFAULT NULL, generation_completed_at DATETIME DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, story_id INT NOT NULL, requested_by_id INT DEFAULT NULL, INDEX idx_audio_status_updated (status, updated_at), INDEX idx_audio_story_status (story_id, status), UNIQUE INDEX uniq_audio_uuid (uuid), INDEX IDX_603DF69FAA5D4036 (story_id), INDEX IDX_603DF69F4DA1E751 (requested_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE audit_log (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, action VARCHAR(64) NOT NULL, actor_identifier VARCHAR(180) NOT NULL, entity_type VARCHAR(64) DEFAULT NULL, entity_id VARCHAR(64) DEFAULT NULL, metadata JSON DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, actor_id INT DEFAULT NULL, INDEX idx_audit_created (created_at), INDEX idx_audit_entity (entity_type, entity_id), INDEX idx_audit_action_created (action, created_at), INDEX IDX_F6E1C0F510DAF24A (actor_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE bookmark (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, story_id INT NOT NULL, INDEX idx_bookmark_user_created (user_id, created_at), UNIQUE INDEX uniq_bookmark_user_story (user_id, story_id), INDEX IDX_DA62921DA76ED395 (user_id), INDEX IDX_DA62921DAA5D4036 (story_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE category (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, name_en VARCHAR(150) DEFAULT NULL, slug VARCHAR(160) NOT NULL, description LONGTEXT DEFAULT NULL, position INT DEFAULT 0 NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, seo_title VARCHAR(255) DEFAULT NULL, seo_description VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, cover_image_id INT DEFAULT NULL, INDEX idx_category_active_position (is_active, position), UNIQUE INDEX uniq_category_slug (slug), INDEX IDX_64C19C1E5A0E336 (cover_image_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE collection_item (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, position INT NOT NULL, collection_id INT NOT NULL, story_id INT NOT NULL, INDEX idx_collection_item_position (collection_id, position), UNIQUE INDEX uniq_collection_item_story (collection_id, story_id), INDEX IDX_556C09F0514956FD (collection_id), INDEX IDX_556C09F0AA5D4036 (story_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE contact_message (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, email VARCHAR(180) NOT NULL, subject VARCHAR(200) NOT NULL, message LONGTEXT NOT NULL, read_at DATETIME DEFAULT NULL, archived TINYINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, INDEX idx_contact_created (created_at), INDEX idx_contact_read (read_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE daily_story_metric (id INT AUTO_INCREMENT NOT NULL, metric_date DATE NOT NULL, views INT DEFAULT 0 NOT NULL, unique_views INT DEFAULT 0 NOT NULL, audio_starts INT DEFAULT 0 NOT NULL, audio_progress25 INT DEFAULT 0 NOT NULL, audio_progress50 INT DEFAULT 0 NOT NULL, audio_progress75 INT DEFAULT 0 NOT NULL, audio_completions INT DEFAULT 0 NOT NULL, bookmarks INT DEFAULT 0 NOT NULL, collection_adds INT DEFAULT 0 NOT NULL, shares INT DEFAULT 0 NOT NULL, donations_initiated INT DEFAULT 0 NOT NULL, donations_declared INT DEFAULT 0 NOT NULL, donations_verified INT DEFAULT 0 NOT NULL, donation_amount_paise BIGINT DEFAULT 0 NOT NULL, story_id INT NOT NULL, INDEX idx_metric_date (metric_date), UNIQUE INDEX uniq_metric_story_date (story_id, metric_date), INDEX IDX_4509D737AA5D4036 (story_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE deity (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, name_en VARCHAR(150) DEFAULT NULL, slug VARCHAR(160) NOT NULL, description LONGTEXT DEFAULT NULL, position INT DEFAULT 0 NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, seo_title VARCHAR(255) DEFAULT NULL, seo_description VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, cover_image_id INT DEFAULT NULL, INDEX idx_deity_active_position (is_active, position), UNIQUE INDEX uniq_deity_slug (slug), INDEX IDX_83E4A464E5A0E336 (cover_image_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE donation (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, donor_name VARCHAR(120) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, phone VARCHAR(20) DEFAULT NULL, amount_paise INT NOT NULL, currency VARCHAR(3) DEFAULT \'INR\' NOT NULL, payment_method VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, payment_note VARCHAR(80) NOT NULL, reference_code VARCHAR(16) NOT NULL, upi_transaction_reference VARCHAR(64) DEFAULT NULL, payment_date DATE DEFAULT NULL, declared_at DATETIME DEFAULT NULL, anonymous TINYINT DEFAULT 0 NOT NULL, notes LONGTEXT DEFAULT NULL, admin_notes LONGTEXT DEFAULT NULL, verified_at DATETIME DEFAULT NULL, rejected_at DATETIME DEFAULT NULL, gateway_name VARCHAR(40) DEFAULT NULL, gateway_payment_id VARCHAR(100) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT DEFAULT NULL, story_id INT DEFAULT NULL, verified_by_id INT DEFAULT NULL, rejected_by_id INT DEFAULT NULL, INDEX idx_donation_status_created (status, created_at), INDEX idx_donation_story_status (story_id, status), INDEX idx_donation_reference (upi_transaction_reference), INDEX idx_donation_created (created_at), UNIQUE INDEX uniq_donation_uuid (uuid), UNIQUE INDEX uniq_donation_reference_code (reference_code), INDEX IDX_31E581A0A76ED395 (user_id), INDEX IDX_31E581A0AA5D4036 (story_id), INDEX IDX_31E581A069F4B775 (verified_by_id), INDEX IDX_31E581A0CBF05FC9 (rejected_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE festival (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, name_en VARCHAR(150) DEFAULT NULL, slug VARCHAR(160) NOT NULL, description LONGTEXT DEFAULT NULL, position INT DEFAULT 0 NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, seo_title VARCHAR(255) DEFAULT NULL, seo_description VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, typical_period VARCHAR(150) DEFAULT NULL, next_occurrence_on DATE DEFAULT NULL, cover_image_id INT DEFAULT NULL, INDEX idx_festival_next (is_active, next_occurrence_on), UNIQUE INDEX uniq_festival_slug (slug), INDEX IDX_57CF789E5A0E336 (cover_image_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE media_file (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, original_name VARCHAR(255) NOT NULL, storage_key VARCHAR(255) NOT NULL, mime_type VARCHAR(50) NOT NULL, file_size INT NOT NULL, width INT DEFAULT NULL, height INT DEFAULT NULL, checksum VARCHAR(64) NOT NULL, variants JSON NOT NULL, alt_text VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, uploaded_by_id INT DEFAULT NULL, INDEX idx_media_checksum (checksum), INDEX idx_media_created (created_at), UNIQUE INDEX uniq_media_uuid (uuid), INDEX IDX_4FD8E9C3A2B28FE8 (uploaded_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE page (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(120) NOT NULL, title VARCHAR(255) NOT NULL, body MEDIUMTEXT NOT NULL, seo_title VARCHAR(255) DEFAULT NULL, seo_description VARCHAR(500) DEFAULT NULL, is_published TINYINT DEFAULT 1 NOT NULL, show_in_footer TINYINT DEFAULT 1 NOT NULL, position INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, updated_by_id INT DEFAULT NULL, INDEX idx_page_footer (is_published, show_in_footer, position), UNIQUE INDEX uniq_page_slug (slug), INDEX IDX_140AB620896DBBDE (updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE pronunciation_rule (id INT AUTO_INCREMENT NOT NULL, source_text VARCHAR(255) NOT NULL, replacement_text VARCHAR(255) NOT NULL, language VARCHAR(10) DEFAULT \'hi\' NOT NULL, case_sensitive TINYINT DEFAULT 0 NOT NULL, whole_word TINYINT DEFAULT 1 NOT NULL, enabled TINYINT DEFAULT 1 NOT NULL, priority INT DEFAULT 0 NOT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_pron_lang_enabled_priority (language, enabled, priority), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE scripture (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, name_en VARCHAR(150) DEFAULT NULL, slug VARCHAR(160) NOT NULL, description LONGTEXT DEFAULT NULL, position INT DEFAULT 0 NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, seo_title VARCHAR(255) DEFAULT NULL, seo_description VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, attributed_to VARCHAR(150) DEFAULT NULL, original_language VARCHAR(80) DEFAULT NULL, cover_image_id INT DEFAULT NULL, INDEX idx_scripture_active_position (is_active, position), UNIQUE INDEX uniq_scripture_slug (slug), INDEX IDX_4A2B206AE5A0E336 (cover_image_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE series (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, name_en VARCHAR(150) DEFAULT NULL, slug VARCHAR(160) NOT NULL, description LONGTEXT DEFAULT NULL, position INT DEFAULT 0 NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, seo_title VARCHAR(255) DEFAULT NULL, seo_description VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, is_completed TINYINT DEFAULT 0 NOT NULL, cover_image_id INT DEFAULT NULL, INDEX idx_series_active_position (is_active, position), UNIQUE INDEX uniq_series_slug (slug), INDEX IDX_3A10012DE5A0E336 (cover_image_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE social_account (id INT AUTO_INCREMENT NOT NULL, platform VARCHAR(20) NOT NULL, display_name VARCHAR(150) NOT NULL, enabled TINYINT DEFAULT 0 NOT NULL, default_template LONGTEXT NOT NULL, default_hashtags VARCHAR(500) DEFAULT NULL, remote_name VARCHAR(255) DEFAULT NULL, last_checked_at DATETIME DEFAULT NULL, last_check_result VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_social_account_platform (platform), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE social_asset (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, status VARCHAR(20) NOT NULL, storage_key VARCHAR(255) DEFAULT NULL, file_size INT DEFAULT NULL, duration_seconds INT DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, type VARCHAR(30) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, created_by_id INT DEFAULT NULL, story_id INT NOT NULL, INDEX idx_social_asset_story (story_id, created_at), UNIQUE INDEX uniq_social_asset_uuid (uuid), INDEX IDX_483FEECEB03A8386 (created_by_id), INDEX IDX_483FEECEAA5D4036 (story_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE social_post (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, status VARCHAR(20) NOT NULL, message LONGTEXT NOT NULL, link VARCHAR(500) NOT NULL, scheduled_at DATETIME DEFAULT NULL, published_at DATETIME DEFAULT NULL, external_post_id VARCHAR(120) DEFAULT NULL, external_url VARCHAR(500) DEFAULT NULL, attempt_count INT DEFAULT 0 NOT NULL, last_error LONGTEXT DEFAULT NULL, last_error_retryable TINYINT DEFAULT 0 NOT NULL, last_attempt_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, story_id INT NOT NULL, account_id INT NOT NULL, created_by_id INT DEFAULT NULL, INDEX idx_social_status_scheduled (status, scheduled_at), INDEX idx_social_created (created_at), UNIQUE INDEX uniq_social_post_uuid (uuid), INDEX IDX_159BBFE9AA5D4036 (story_id), INDEX IDX_159BBFE99B6B5FBA (account_id), INDEX IDX_159BBFE9B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE social_post_attempt (id INT AUTO_INCREMENT NOT NULL, attempted_at DATETIME NOT NULL, success TINYINT NOT NULL, http_status INT DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, response_excerpt LONGTEXT DEFAULT NULL, duration_ms INT DEFAULT NULL, post_id INT NOT NULL, INDEX idx_social_attempt_post (post_id, attempted_at), INDEX IDX_F9A71C6A4B89032C (post_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE story (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, title VARCHAR(255) NOT NULL, original_title VARCHAR(255) DEFAULT NULL, slug VARCHAR(190) NOT NULL, subtitle VARCHAR(255) DEFAULT NULL, excerpt LONGTEXT NOT NULL, body MEDIUMTEXT NOT NULL, search_text MEDIUMTEXT NOT NULL, language VARCHAR(10) DEFAULT \'hi\' NOT NULL, word_count INT DEFAULT 0 NOT NULL, reading_time_minutes INT DEFAULT 1 NOT NULL, status VARCHAR(32) NOT NULL, featured TINYINT DEFAULT 0 NOT NULL, premium TINYINT DEFAULT 0 NOT NULL, published_at DATETIME DEFAULT NULL, first_published_at DATETIME DEFAULT NULL, scheduled_at DATETIME DEFAULT NULL, archived_at DATETIME DEFAULT NULL, reviewed_at DATETIME DEFAULT NULL, review_notes LONGTEXT DEFAULT NULL, source_type VARCHAR(40) NOT NULL, verification_status VARCHAR(32) NOT NULL, source_verified_at DATETIME DEFAULT NULL, verification_notes LONGTEXT DEFAULT NULL, source_notes LONGTEXT DEFAULT NULL, series_position INT DEFAULT NULL, audio_enabled TINYINT DEFAULT 1 NOT NULL, tts_voice VARCHAR(100) DEFAULT NULL, audio_status VARCHAR(20) DEFAULT NULL, audio_duration_seconds INT DEFAULT NULL, audio_outdated TINYINT DEFAULT 0 NOT NULL, seo_title VARCHAR(255) DEFAULT NULL, seo_description VARCHAR(500) DEFAULT NULL, canonical_url VARCHAR(500) DEFAULT NULL, robots_index TINYINT DEFAULT 1 NOT NULL, robots_follow TINYINT DEFAULT 1 NOT NULL, facebook_title VARCHAR(255) DEFAULT NULL, facebook_description VARCHAR(500) DEFAULT NULL, auto_publish_facebook TINYINT DEFAULT 0 NOT NULL, show_donation_cta TINYINT DEFAULT 1 NOT NULL, donation_message LONGTEXT DEFAULT NULL, view_count INT DEFAULT 0 NOT NULL, listen_count INT DEFAULT 0 NOT NULL, completion_count INT DEFAULT 0 NOT NULL, bookmark_count INT DEFAULT 0 NOT NULL, share_count INT DEFAULT 0 NOT NULL, donation_count INT DEFAULT 0 NOT NULL, donation_amount_paise BIGINT DEFAULT 0 NOT NULL, popularity_score INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, created_by_id INT DEFAULT NULL, updated_by_id INT DEFAULT NULL, reviewed_by_id INT DEFAULT NULL, source_verified_by_id INT DEFAULT NULL, scripture_id INT DEFAULT NULL, series_id INT DEFAULT NULL, cover_image_id INT DEFAULT NULL, og_image_id INT DEFAULT NULL, current_audio_id INT DEFAULT NULL, INDEX idx_story_status_published (status, published_at), INDEX idx_story_featured_status (featured, status, published_at), INDEX idx_story_status_scheduled (status, scheduled_at), INDEX idx_story_status_popularity (status, popularity_score), INDEX idx_story_status_duration (status, audio_duration_seconds), INDEX idx_story_audio_status (audio_status), INDEX idx_story_series_position (series_id, series_position), INDEX idx_story_updated (updated_at), FULLTEXT INDEX ft_story_title (title, subtitle, original_title), FULLTEXT INDEX ft_story_search (title, subtitle, excerpt, search_text), UNIQUE INDEX uniq_story_uuid (uuid), UNIQUE INDEX uniq_story_slug (slug), INDEX IDX_EB560438B03A8386 (created_by_id), INDEX IDX_EB560438896DBBDE (updated_by_id), INDEX IDX_EB560438FC6B21F1 (reviewed_by_id), INDEX IDX_EB560438CA8DDBD0 (source_verified_by_id), INDEX IDX_EB5604381A39566E (scripture_id), INDEX IDX_EB5604385278319C (series_id), INDEX IDX_EB560438E5A0E336 (cover_image_id), INDEX IDX_EB5604386EFCB8B8 (og_image_id), INDEX IDX_EB560438A74959ED (current_audio_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE story_category (story_id INT NOT NULL, category_id INT NOT NULL, INDEX IDX_5A9075DAA5D4036 (story_id), INDEX IDX_5A9075D12469DE2 (category_id), PRIMARY KEY (story_id, category_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE story_tag (story_id INT NOT NULL, tag_id INT NOT NULL, INDEX IDX_A74D17C9AA5D4036 (story_id), INDEX IDX_A74D17C9BAD26311 (tag_id), PRIMARY KEY (story_id, tag_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE story_deity (story_id INT NOT NULL, deity_id INT NOT NULL, INDEX IDX_5FA404C7AA5D4036 (story_id), INDEX IDX_5FA404C74493F9C3 (deity_id), PRIMARY KEY (story_id, deity_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE story_festival (story_id INT NOT NULL, festival_id INT NOT NULL, INDEX IDX_699E915AA5D4036 (story_id), INDEX IDX_699E9158AEBAF57 (festival_id), PRIMARY KEY (story_id, festival_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE story_progress (id INT AUTO_INCREMENT NOT NULL, audio_position_seconds INT DEFAULT 0 NOT NULL, audio_duration_seconds INT DEFAULT NULL, reading_progress_percent SMALLINT DEFAULT 0 NOT NULL, audio_progress_percent SMALLINT DEFAULT 0 NOT NULL, completed TINYINT DEFAULT 0 NOT NULL, completed_at DATETIME DEFAULT NULL, last_played_at DATETIME DEFAULT NULL, last_read_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, story_id INT NOT NULL, INDEX idx_progress_user_updated (user_id, updated_at), INDEX idx_progress_user_played (user_id, last_played_at), INDEX idx_progress_user_read (user_id, last_read_at), UNIQUE INDEX uniq_progress_user_story (user_id, story_id), INDEX IDX_21E4ECDAA76ED395 (user_id), INDEX IDX_21E4ECDAAA5D4036 (story_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE story_revision (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, version INT NOT NULL, title VARCHAR(255) NOT NULL, subtitle VARCHAR(255) DEFAULT NULL, excerpt LONGTEXT NOT NULL, body MEDIUMTEXT NOT NULL, source_snapshot JSON NOT NULL, change_summary VARCHAR(255) DEFAULT NULL, story_id INT NOT NULL, changed_by_id INT DEFAULT NULL, UNIQUE INDEX uniq_story_revision_version (story_id, version), INDEX IDX_6E860B50AA5D4036 (story_id), INDEX IDX_6E860B50828AD0A0 (changed_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE story_slug_history (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, slug VARCHAR(190) NOT NULL, story_id INT NOT NULL, UNIQUE INDEX uniq_story_slug_history_slug (slug), INDEX IDX_3753FB0FAA5D4036 (story_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE story_source_reference (id INT AUTO_INCREMENT NOT NULL, source_title VARCHAR(255) DEFAULT NULL, volume VARCHAR(120) DEFAULT NULL, chapter VARCHAR(120) DEFAULT NULL, section VARCHAR(120) DEFAULT NULL, verse_references VARCHAR(255) DEFAULT NULL, edition VARCHAR(255) DEFAULT NULL, translator VARCHAR(255) DEFAULT NULL, url VARCHAR(500) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, position INT DEFAULT 0 NOT NULL, story_id INT NOT NULL, scripture_id INT DEFAULT NULL, INDEX idx_source_ref_story_position (story_id, position), INDEX IDX_88DA6C11AA5D4036 (story_id), INDEX IDX_88DA6C111A39566E (scripture_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE system_heartbeat (id INT AUTO_INCREMENT NOT NULL, last_run_at DATETIME NOT NULL, last_status VARCHAR(20) NOT NULL, last_message LONGTEXT DEFAULT NULL, last_duration_ms INT DEFAULT NULL, name VARCHAR(100) NOT NULL, UNIQUE INDEX uniq_heartbeat_name (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE tag (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, name_en VARCHAR(150) DEFAULT NULL, slug VARCHAR(160) NOT NULL, description LONGTEXT DEFAULT NULL, position INT DEFAULT 0 NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, seo_title VARCHAR(255) DEFAULT NULL, seo_description VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, cover_image_id INT DEFAULT NULL, UNIQUE INDEX uniq_tag_slug (slug), INDEX IDX_389B783E5A0E336 (cover_image_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE user_collection (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, name VARCHAR(120) NOT NULL, slug VARCHAR(140) NOT NULL, description LONGTEXT DEFAULT NULL, visibility VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX idx_collection_visibility_updated (visibility, updated_at), UNIQUE INDEX uniq_collection_uuid (uuid), UNIQUE INDEX uniq_collection_user_slug (user_id, slug), INDEX IDX_5B2AA3DEA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE account_token ADD CONSTRAINT FK_69B8784AA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE app_setting ADD CONSTRAINT FK_722938D5896DBBDE FOREIGN KEY (updated_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE audio_asset ADD CONSTRAINT FK_603DF69FAA5D4036 FOREIGN KEY (story_id) REFERENCES story (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE audio_asset ADD CONSTRAINT FK_603DF69F4DA1E751 FOREIGN KEY (requested_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT FK_F6E1C0F510DAF24A FOREIGN KEY (actor_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE bookmark ADD CONSTRAINT FK_DA62921DA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bookmark ADD CONSTRAINT FK_DA62921DAA5D4036 FOREIGN KEY (story_id) REFERENCES story (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE category ADD CONSTRAINT FK_64C19C1E5A0E336 FOREIGN KEY (cover_image_id) REFERENCES media_file (id)');
        $this->addSql('ALTER TABLE collection_item ADD CONSTRAINT FK_556C09F0514956FD FOREIGN KEY (collection_id) REFERENCES user_collection (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE collection_item ADD CONSTRAINT FK_556C09F0AA5D4036 FOREIGN KEY (story_id) REFERENCES story (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE daily_story_metric ADD CONSTRAINT FK_4509D737AA5D4036 FOREIGN KEY (story_id) REFERENCES story (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE deity ADD CONSTRAINT FK_83E4A464E5A0E336 FOREIGN KEY (cover_image_id) REFERENCES media_file (id)');
        $this->addSql('ALTER TABLE donation ADD CONSTRAINT FK_31E581A0A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE donation ADD CONSTRAINT FK_31E581A0AA5D4036 FOREIGN KEY (story_id) REFERENCES story (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE donation ADD CONSTRAINT FK_31E581A069F4B775 FOREIGN KEY (verified_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE donation ADD CONSTRAINT FK_31E581A0CBF05FC9 FOREIGN KEY (rejected_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE festival ADD CONSTRAINT FK_57CF789E5A0E336 FOREIGN KEY (cover_image_id) REFERENCES media_file (id)');
        $this->addSql('ALTER TABLE media_file ADD CONSTRAINT FK_4FD8E9C3A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE page ADD CONSTRAINT FK_140AB620896DBBDE FOREIGN KEY (updated_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE scripture ADD CONSTRAINT FK_4A2B206AE5A0E336 FOREIGN KEY (cover_image_id) REFERENCES media_file (id)');
        $this->addSql('ALTER TABLE series ADD CONSTRAINT FK_3A10012DE5A0E336 FOREIGN KEY (cover_image_id) REFERENCES media_file (id)');
        $this->addSql('ALTER TABLE social_asset ADD CONSTRAINT FK_483FEECEB03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE social_asset ADD CONSTRAINT FK_483FEECEAA5D4036 FOREIGN KEY (story_id) REFERENCES story (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE social_post ADD CONSTRAINT FK_159BBFE9AA5D4036 FOREIGN KEY (story_id) REFERENCES story (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE social_post ADD CONSTRAINT FK_159BBFE99B6B5FBA FOREIGN KEY (account_id) REFERENCES social_account (id)');
        $this->addSql('ALTER TABLE social_post ADD CONSTRAINT FK_159BBFE9B03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE social_post_attempt ADD CONSTRAINT FK_F9A71C6A4B89032C FOREIGN KEY (post_id) REFERENCES social_post (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE story ADD CONSTRAINT FK_EB560438B03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE story ADD CONSTRAINT FK_EB560438896DBBDE FOREIGN KEY (updated_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE story ADD CONSTRAINT FK_EB560438FC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE story ADD CONSTRAINT FK_EB560438CA8DDBD0 FOREIGN KEY (source_verified_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE story ADD CONSTRAINT FK_EB5604381A39566E FOREIGN KEY (scripture_id) REFERENCES scripture (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE story ADD CONSTRAINT FK_EB5604385278319C FOREIGN KEY (series_id) REFERENCES series (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE story ADD CONSTRAINT FK_EB560438E5A0E336 FOREIGN KEY (cover_image_id) REFERENCES media_file (id)');
        $this->addSql('ALTER TABLE story ADD CONSTRAINT FK_EB5604386EFCB8B8 FOREIGN KEY (og_image_id) REFERENCES media_file (id)');
        $this->addSql('ALTER TABLE story ADD CONSTRAINT FK_EB560438A74959ED FOREIGN KEY (current_audio_id) REFERENCES audio_asset (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE story_category ADD CONSTRAINT FK_5A9075DAA5D4036 FOREIGN KEY (story_id) REFERENCES story (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE story_category ADD CONSTRAINT FK_5A9075D12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE story_tag ADD CONSTRAINT FK_A74D17C9AA5D4036 FOREIGN KEY (story_id) REFERENCES story (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE story_tag ADD CONSTRAINT FK_A74D17C9BAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE story_deity ADD CONSTRAINT FK_5FA404C7AA5D4036 FOREIGN KEY (story_id) REFERENCES story (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE story_deity ADD CONSTRAINT FK_5FA404C74493F9C3 FOREIGN KEY (deity_id) REFERENCES deity (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE story_festival ADD CONSTRAINT FK_699E915AA5D4036 FOREIGN KEY (story_id) REFERENCES story (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE story_festival ADD CONSTRAINT FK_699E9158AEBAF57 FOREIGN KEY (festival_id) REFERENCES festival (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE story_progress ADD CONSTRAINT FK_21E4ECDAA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE story_progress ADD CONSTRAINT FK_21E4ECDAAA5D4036 FOREIGN KEY (story_id) REFERENCES story (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE story_revision ADD CONSTRAINT FK_6E860B50AA5D4036 FOREIGN KEY (story_id) REFERENCES story (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE story_revision ADD CONSTRAINT FK_6E860B50828AD0A0 FOREIGN KEY (changed_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE story_slug_history ADD CONSTRAINT FK_3753FB0FAA5D4036 FOREIGN KEY (story_id) REFERENCES story (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE story_source_reference ADD CONSTRAINT FK_88DA6C11AA5D4036 FOREIGN KEY (story_id) REFERENCES story (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE story_source_reference ADD CONSTRAINT FK_88DA6C111A39566E FOREIGN KEY (scripture_id) REFERENCES scripture (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE tag ADD CONSTRAINT FK_389B783E5A0E336 FOREIGN KEY (cover_image_id) REFERENCES media_file (id)');
        $this->addSql('ALTER TABLE user_collection ADD CONSTRAINT FK_5B2AA3DEA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account_token DROP FOREIGN KEY FK_69B8784AA76ED395');
        $this->addSql('ALTER TABLE app_setting DROP FOREIGN KEY FK_722938D5896DBBDE');
        $this->addSql('ALTER TABLE audio_asset DROP FOREIGN KEY FK_603DF69FAA5D4036');
        $this->addSql('ALTER TABLE audio_asset DROP FOREIGN KEY FK_603DF69F4DA1E751');
        $this->addSql('ALTER TABLE audit_log DROP FOREIGN KEY FK_F6E1C0F510DAF24A');
        $this->addSql('ALTER TABLE bookmark DROP FOREIGN KEY FK_DA62921DA76ED395');
        $this->addSql('ALTER TABLE bookmark DROP FOREIGN KEY FK_DA62921DAA5D4036');
        $this->addSql('ALTER TABLE category DROP FOREIGN KEY FK_64C19C1E5A0E336');
        $this->addSql('ALTER TABLE collection_item DROP FOREIGN KEY FK_556C09F0514956FD');
        $this->addSql('ALTER TABLE collection_item DROP FOREIGN KEY FK_556C09F0AA5D4036');
        $this->addSql('ALTER TABLE daily_story_metric DROP FOREIGN KEY FK_4509D737AA5D4036');
        $this->addSql('ALTER TABLE deity DROP FOREIGN KEY FK_83E4A464E5A0E336');
        $this->addSql('ALTER TABLE donation DROP FOREIGN KEY FK_31E581A0A76ED395');
        $this->addSql('ALTER TABLE donation DROP FOREIGN KEY FK_31E581A0AA5D4036');
        $this->addSql('ALTER TABLE donation DROP FOREIGN KEY FK_31E581A069F4B775');
        $this->addSql('ALTER TABLE donation DROP FOREIGN KEY FK_31E581A0CBF05FC9');
        $this->addSql('ALTER TABLE festival DROP FOREIGN KEY FK_57CF789E5A0E336');
        $this->addSql('ALTER TABLE media_file DROP FOREIGN KEY FK_4FD8E9C3A2B28FE8');
        $this->addSql('ALTER TABLE page DROP FOREIGN KEY FK_140AB620896DBBDE');
        $this->addSql('ALTER TABLE scripture DROP FOREIGN KEY FK_4A2B206AE5A0E336');
        $this->addSql('ALTER TABLE series DROP FOREIGN KEY FK_3A10012DE5A0E336');
        $this->addSql('ALTER TABLE social_asset DROP FOREIGN KEY FK_483FEECEB03A8386');
        $this->addSql('ALTER TABLE social_asset DROP FOREIGN KEY FK_483FEECEAA5D4036');
        $this->addSql('ALTER TABLE social_post DROP FOREIGN KEY FK_159BBFE9AA5D4036');
        $this->addSql('ALTER TABLE social_post DROP FOREIGN KEY FK_159BBFE99B6B5FBA');
        $this->addSql('ALTER TABLE social_post DROP FOREIGN KEY FK_159BBFE9B03A8386');
        $this->addSql('ALTER TABLE social_post_attempt DROP FOREIGN KEY FK_F9A71C6A4B89032C');
        $this->addSql('ALTER TABLE story DROP FOREIGN KEY FK_EB560438B03A8386');
        $this->addSql('ALTER TABLE story DROP FOREIGN KEY FK_EB560438896DBBDE');
        $this->addSql('ALTER TABLE story DROP FOREIGN KEY FK_EB560438FC6B21F1');
        $this->addSql('ALTER TABLE story DROP FOREIGN KEY FK_EB560438CA8DDBD0');
        $this->addSql('ALTER TABLE story DROP FOREIGN KEY FK_EB5604381A39566E');
        $this->addSql('ALTER TABLE story DROP FOREIGN KEY FK_EB5604385278319C');
        $this->addSql('ALTER TABLE story DROP FOREIGN KEY FK_EB560438E5A0E336');
        $this->addSql('ALTER TABLE story DROP FOREIGN KEY FK_EB5604386EFCB8B8');
        $this->addSql('ALTER TABLE story DROP FOREIGN KEY FK_EB560438A74959ED');
        $this->addSql('ALTER TABLE story_category DROP FOREIGN KEY FK_5A9075DAA5D4036');
        $this->addSql('ALTER TABLE story_category DROP FOREIGN KEY FK_5A9075D12469DE2');
        $this->addSql('ALTER TABLE story_tag DROP FOREIGN KEY FK_A74D17C9AA5D4036');
        $this->addSql('ALTER TABLE story_tag DROP FOREIGN KEY FK_A74D17C9BAD26311');
        $this->addSql('ALTER TABLE story_deity DROP FOREIGN KEY FK_5FA404C7AA5D4036');
        $this->addSql('ALTER TABLE story_deity DROP FOREIGN KEY FK_5FA404C74493F9C3');
        $this->addSql('ALTER TABLE story_festival DROP FOREIGN KEY FK_699E915AA5D4036');
        $this->addSql('ALTER TABLE story_festival DROP FOREIGN KEY FK_699E9158AEBAF57');
        $this->addSql('ALTER TABLE story_progress DROP FOREIGN KEY FK_21E4ECDAA76ED395');
        $this->addSql('ALTER TABLE story_progress DROP FOREIGN KEY FK_21E4ECDAAA5D4036');
        $this->addSql('ALTER TABLE story_revision DROP FOREIGN KEY FK_6E860B50AA5D4036');
        $this->addSql('ALTER TABLE story_revision DROP FOREIGN KEY FK_6E860B50828AD0A0');
        $this->addSql('ALTER TABLE story_slug_history DROP FOREIGN KEY FK_3753FB0FAA5D4036');
        $this->addSql('ALTER TABLE story_source_reference DROP FOREIGN KEY FK_88DA6C11AA5D4036');
        $this->addSql('ALTER TABLE story_source_reference DROP FOREIGN KEY FK_88DA6C111A39566E');
        $this->addSql('ALTER TABLE tag DROP FOREIGN KEY FK_389B783E5A0E336');
        $this->addSql('ALTER TABLE user_collection DROP FOREIGN KEY FK_5B2AA3DEA76ED395');
        $this->addSql('DROP TABLE account_token');
        $this->addSql('DROP TABLE admin_notification');
        $this->addSql('DROP TABLE app_setting');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE audio_asset');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE bookmark');
        $this->addSql('DROP TABLE category');
        $this->addSql('DROP TABLE collection_item');
        $this->addSql('DROP TABLE contact_message');
        $this->addSql('DROP TABLE daily_story_metric');
        $this->addSql('DROP TABLE deity');
        $this->addSql('DROP TABLE donation');
        $this->addSql('DROP TABLE festival');
        $this->addSql('DROP TABLE media_file');
        $this->addSql('DROP TABLE page');
        $this->addSql('DROP TABLE pronunciation_rule');
        $this->addSql('DROP TABLE scripture');
        $this->addSql('DROP TABLE series');
        $this->addSql('DROP TABLE social_account');
        $this->addSql('DROP TABLE social_asset');
        $this->addSql('DROP TABLE social_post');
        $this->addSql('DROP TABLE social_post_attempt');
        $this->addSql('DROP TABLE story');
        $this->addSql('DROP TABLE story_category');
        $this->addSql('DROP TABLE story_tag');
        $this->addSql('DROP TABLE story_deity');
        $this->addSql('DROP TABLE story_festival');
        $this->addSql('DROP TABLE story_progress');
        $this->addSql('DROP TABLE story_revision');
        $this->addSql('DROP TABLE story_slug_history');
        $this->addSql('DROP TABLE story_source_reference');
        $this->addSql('DROP TABLE system_heartbeat');
        $this->addSql('DROP TABLE tag');
        $this->addSql('DROP TABLE user_collection');
        $this->addSql('DROP TABLE messenger_messages');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
