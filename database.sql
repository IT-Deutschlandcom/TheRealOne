CREATE DATABASE IF NOT EXISTS marketing_cms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE marketing_cms;

CREATE TABLE roles (
    id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(40) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO roles (id, name) VALUES
(1, 'superadmin'), (2, 'admin'), (3, 'campaign_manager'), (4, 'viewer');

CREATE TABLE admins (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_id TINYINT UNSIGNED NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admins_email (email),
    CONSTRAINT fk_admins_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_activity_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    metadata_json JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_activity_created (created_at),
    KEY ix_activity_admin (admin_id, created_at),
    CONSTRAINT fk_activity_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_hash CHAR(64) NOT NULL,
    attempted_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_login_attempts_ip_time (ip_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_lists (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_email_lists_name (name),
    CONSTRAINT fk_email_lists_admin FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_contacts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(254) NOT NULL,
    normalized_email VARCHAR(254) NOT NULL,
    first_name VARCHAR(120) NULL,
    last_name VARCHAR(120) NULL,
    status ENUM('pending', 'active', 'unsubscribed', 'bounced') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_contacts_normalized_email (normalized_email),
    KEY ix_contacts_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_list_contacts (
    list_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (list_id, contact_id),
    CONSTRAINT fk_list_contacts_list FOREIGN KEY (list_id) REFERENCES email_lists(id) ON DELETE CASCADE,
    CONSTRAINT fk_list_contacts_contact FOREIGN KEY (contact_id) REFERENCES email_contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_consents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contact_id BIGINT UNSIGNED NOT NULL,
    legal_basis ENUM('consent', 'existing_customer') NOT NULL,
    evidence_source VARCHAR(500) NOT NULL,
    granted_at DATETIME NOT NULL,
    recorded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_contact_consents_contact (contact_id, granted_at),
    CONSTRAINT fk_consents_contact FOREIGN KEY (contact_id) REFERENCES email_contacts(id) ON DELETE CASCADE,
    CONSTRAINT fk_consents_admin FOREIGN KEY (recorded_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE suppression_list (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    normalized_email VARCHAR(254) NOT NULL,
    reason ENUM('unsubscribe', 'hard_bounce', 'soft_bounce', 'manual') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_suppression_email (normalized_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_imports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    list_id BIGINT UNSIGNED NOT NULL,
    admin_id BIGINT UNSIGNED NULL,
    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    imported_count INT UNSIGNED NOT NULL DEFAULT 0,
    duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
    invalid_count INT UNSIGNED NOT NULL DEFAULT 0,
    suppressed_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_imports_list (list_id, created_at),
    CONSTRAINT fk_imports_list FOREIGN KEY (list_id) REFERENCES email_lists(id) ON DELETE CASCADE,
    CONSTRAINT fk_imports_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE campaigns (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    list_id BIGINT UNSIGNED NOT NULL,
    template_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    name VARCHAR(180) NOT NULL,
    from_name VARCHAR(160) NOT NULL,
    from_email VARCHAR(254) NOT NULL,
    reply_to VARCHAR(254) NULL,
    subject VARCHAR(255) NOT NULL,
    html_content MEDIUMTEXT NOT NULL,
    text_content MEDIUMTEXT NOT NULL,
    status ENUM('draft', 'queued', 'sending', 'paused', 'completed', 'needs_review', 'archived', 'cancelled') NOT NULL DEFAULT 'draft',
    send_interval_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 180,
    next_send_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_campaigns_status_updated (status, updated_at),
    KEY ix_campaigns_list_status (list_id, status),
    CONSTRAINT fk_campaigns_list FOREIGN KEY (list_id) REFERENCES email_lists(id) ON DELETE RESTRICT,
    CONSTRAINT fk_campaigns_created_by FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL,
    CONSTRAINT fk_campaigns_updated_by FOREIGN KEY (updated_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

ALTER TABLE smtp_profiles
    ADD COLUMN proxy_id BIGINT UNSIGNED NULL,
    ADD CONSTRAINT fk_smtp_profiles_proxy FOREIGN KEY (proxy_id) REFERENCES proxies(id) ON DELETE RESTRICT;

CREATE TABLE worker_heartbeats (
    worker_name VARCHAR(80) NOT NULL,
    last_seen_at DATETIME NOT NULL,
    details VARCHAR(255) NULL,
    PRIMARY KEY (worker_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
