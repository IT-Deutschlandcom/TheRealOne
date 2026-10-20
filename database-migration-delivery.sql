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

ALTER TABLE campaigns ADD COLUMN template_id BIGINT UNSIGNED NULL;
ALTER TABLE suppression_list
    MODIFY reason ENUM('unsubscribe', 'hard_bounce', 'soft_bounce', 'manual') NOT NULL;

CREATE TABLE email_template_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_template_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    name VARCHAR(180) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    html_content MEDIUMTEXT NOT NULL,
    text_content MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_email_templates_category (category_id),
    CONSTRAINT fk_email_templates_category FOREIGN KEY (category_id) REFERENCES email_template_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_email_templates_admin FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE campaigns ADD CONSTRAINT fk_campaigns_template
    FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE SET NULL;

CREATE TABLE bounce_mailboxes (
    id TINYINT UNSIGNED NOT NULL,
    host VARCHAR(253) NOT NULL,
    port SMALLINT UNSIGNED NOT NULL,
    encryption ENUM('ssl', 'tls') NOT NULL DEFAULT 'ssl',
    username VARCHAR(254) NOT NULL,
    password_encrypted TEXT NOT NULL,
    folder_name VARCHAR(120) NOT NULL DEFAULT 'INBOX',
    last_uid BIGINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    last_checked_at DATETIME NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_bounce_mailboxes_admin FOREIGN KEY (updated_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bounce_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    mailbox_id TINYINT UNSIGNED NOT NULL DEFAULT 1,
    source_uid BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NULL,
    campaign_id BIGINT UNSIGNED NULL,
    queue_id BIGINT UNSIGNED NULL,
    bounce_type ENUM('hard', 'soft') NOT NULL,
    smtp_status VARCHAR(20) NULL,
    diagnostic VARCHAR(500) NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bounce_mailbox_uid (mailbox_id, source_uid),
    KEY ix_bounce_contact_date (contact_id, received_at),
    KEY ix_bounce_campaign (campaign_id, received_at),
    CONSTRAINT fk_bounce_contact FOREIGN KEY (contact_id) REFERENCES email_contacts(id) ON DELETE SET NULL,
    CONSTRAINT fk_bounce_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
    CONSTRAINT fk_bounce_queue FOREIGN KEY (queue_id) REFERENCES campaign_queue(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE proxy_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_proxy_groups_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE proxies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_id BIGINT UNSIGNED NULL,
    host VARCHAR(253) NOT NULL,
    port SMALLINT UNSIGNED NOT NULL,
    username VARCHAR(254) NULL,
    password_encrypted TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_check_at DATETIME NULL,
    notes VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_proxies_host_port (host, port),
    CONSTRAINT fk_proxies_group FOREIGN KEY (group_id) REFERENCES proxy_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE worker_heartbeats (
    worker_name VARCHAR(80) NOT NULL,
    last_seen_at DATETIME NOT NULL,
    details VARCHAR(255) NULL,
    PRIMARY KEY (worker_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
