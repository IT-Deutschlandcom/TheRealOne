USE marketing_cms;

ALTER TABLE campaigns
    MODIFY status ENUM('draft', 'queued', 'sending', 'paused', 'completed', 'needs_review', 'archived', 'cancelled') NOT NULL DEFAULT 'draft',
    ADD COLUMN send_interval_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 180,
    ADD COLUMN next_send_at DATETIME NULL;

CREATE TABLE smtp_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    host VARCHAR(253) NOT NULL,
    port SMALLINT UNSIGNED NOT NULL,
    encryption ENUM('tls', 'ssl', 'none') NOT NULL,
    username VARCHAR(254) NULL,
    password_encrypted TEXT NULL,
    from_email VARCHAR(254) NOT NULL,
    from_name VARCHAR(160) NOT NULL,
    timeout_seconds TINYINT UNSIGNED NOT NULL DEFAULT 20,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    last_test_at DATETIME NULL,
    last_test_success TINYINT(1) NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_smtp_profiles_name (name),
    KEY ix_smtp_profiles_active (is_active),
    CONSTRAINT fk_smtp_profiles_admin FOREIGN KEY (updated_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE campaign_queue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    status ENUM('queued', 'processing', 'accepted', 'failed', 'unknown', 'cancelled') NOT NULL DEFAULT 'queued',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    processing_started_at DATETIME NULL,
    completed_at DATETIME NULL,
    smtp_code SMALLINT UNSIGNED NULL,
    last_error VARCHAR(500) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_campaign_queue_recipient (campaign_id, contact_id),
    KEY ix_campaign_queue_due (status, available_at, campaign_id),
    KEY ix_campaign_queue_processing (status, processing_started_at),
    CONSTRAINT fk_campaign_queue_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_campaign_queue_contact FOREIGN KEY (contact_id) REFERENCES email_contacts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE unsubscribe_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contact_id BIGINT UNSIGNED NOT NULL,
    source VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_unsubscribe_contact (contact_id, created_at),
    CONSTRAINT fk_unsubscribe_contact FOREIGN KEY (contact_id) REFERENCES email_contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
