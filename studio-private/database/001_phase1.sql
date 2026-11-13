-- Phase 1; MySQL 8 / MariaDB 10.6+; import only into a new dedicated database.
CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(80) PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS admin_users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, singleton_slot TINYINT NOT NULL DEFAULT 1,
 email VARCHAR(190) NOT NULL UNIQUE, display_name VARCHAR(100) NOT NULL, password_hash VARCHAR(255) NOT NULL,
 active TINYINT NOT NULL DEFAULT 1, last_login_at DATETIME NULL, created_at DATETIME NOT NULL,
 UNIQUE KEY single_admin (singleton_slot), CONSTRAINT ck_single_admin CHECK (singleton_slot = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS system_settings (setting_key VARCHAR(100) PRIMARY KEY, value_json LONGTEXT NOT NULL, updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS social_connections (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, admin_id BIGINT UNSIGNED NOT NULL, provider VARCHAR(30) NOT NULL, provider_subject_id VARCHAR(190) NOT NULL,
 encrypted_access_token TEXT NULL, encrypted_refresh_token TEXT NULL, expires_at DATETIME NULL, granted_scopes TEXT NULL, status VARCHAR(30) NOT NULL DEFAULT 'NOT_CONNECTED', key_version INT NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE KEY provider_subject (provider, provider_subject_id), KEY connection_expiry (status, expires_at), FOREIGN KEY (admin_id) REFERENCES admin_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS social_accounts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, connection_id BIGINT UNSIGNED NULL, platform VARCHAR(30) NOT NULL, external_account_id VARCHAR(190) NOT NULL,
 display_name VARCHAR(190) NOT NULL, enabled TINYINT NOT NULL DEFAULT 1, connection_status VARCHAR(30) NOT NULL DEFAULT 'NOT_CONNECTED', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 UNIQUE KEY account_external (platform, external_account_id), UNIQUE KEY account_platform (id, platform), KEY active_accounts (enabled, platform),
 CONSTRAINT ck_platform CHECK (platform IN ('facebook_page','youtube','facebook_personal')), FOREIGN KEY (connection_id) REFERENCES social_connections(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS facebook_pages (account_id BIGINT UNSIGNED PRIMARY KEY, page_id VARCHAR(190) NOT NULL UNIQUE, permissions_status VARCHAR(30) NOT NULL DEFAULT 'NOT_CONNECTED', permission_check_at DATETIME NULL, encrypted_page_token TEXT NULL, token_expires_at DATETIME NULL, FOREIGN KEY(account_id) REFERENCES social_accounts(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS youtube_channels (account_id BIGINT UNSIGNED PRIMARY KEY, channel_id VARCHAR(190) NOT NULL UNIQUE, upload_capability_status VARCHAR(30) NOT NULL DEFAULT 'NOT_CONNECTED', FOREIGN KEY(account_id) REFERENCES social_accounts(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS personal_profiles (account_id BIGINT UNSIGNED PRIMARY KEY, singleton_slot TINYINT NOT NULL DEFAULT 1 UNIQUE, profile_url VARCHAR(2048) NULL, FOREIGN KEY(account_id) REFERENCES social_accounts(id), CONSTRAINT ck_personal_slot CHECK(singleton_slot=1)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS account_monetization_history (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, account_id BIGINT UNSIGNED NOT NULL, status VARCHAR(30) NOT NULL, effective_from DATETIME NOT NULL, effective_to DATETIME NULL, source VARCHAR(100) NOT NULL, KEY monetization_period(account_id,effective_from), FOREIGN KEY(account_id) REFERENCES social_accounts(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS media_assets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, storage_disk VARCHAR(30) NOT NULL DEFAULT 'local', storage_key VARCHAR(190) NOT NULL, sha256 CHAR(64) NOT NULL UNIQUE,
 original_name VARCHAR(255) NOT NULL, mime VARCHAR(100) NOT NULL, size_bytes BIGINT UNSIGNED NOT NULL, width INT UNSIGNED NOT NULL, height INT UNSIGNED NOT NULL, duration_ms INT UNSIGNED NOT NULL,
 video_codec VARCHAR(100) NOT NULL, audio_codec VARCHAR(100) NULL, validation_status VARCHAR(30) NOT NULL, validation_json LONGTEXT NOT NULL, source_type VARCHAR(30) NOT NULL DEFAULT 'upload', created_at DATETIME NOT NULL,
 UNIQUE KEY disk_key(storage_disk,storage_key), KEY media_status(validation_status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS content_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, admin_id BIGINT UNSIGNED NOT NULL, media_asset_id BIGINT UNSIGNED NULL UNIQUE, title_internal VARCHAR(190) NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'DRAFT', revision INT UNSIGNED NOT NULL DEFAULT 1, desired_publish_at_utc DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 KEY content_status(status,desired_publish_at_utc), FOREIGN KEY(admin_id) REFERENCES admin_users(id), FOREIGN KEY(media_asset_id) REFERENCES media_assets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS content_captions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, content_id BIGINT UNSIGNED NOT NULL, platform VARCHAR(30) NOT NULL, caption TEXT NOT NULL, hashtags TEXT NOT NULL, youtube_title VARCHAR(100) NOT NULL DEFAULT '', revision INT UNSIGNED NOT NULL, UNIQUE KEY content_platform(content_id,platform), FOREIGN KEY(content_id) REFERENCES content_items(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS content_approvals (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, content_id BIGINT UNSIGNED NOT NULL, admin_id BIGINT UNSIGNED NOT NULL, revision INT UNSIGNED NOT NULL, snapshot LONGTEXT NOT NULL, snapshot_sha256 CHAR(64) NOT NULL, approved_at DATETIME NOT NULL, UNIQUE KEY approved_revision(content_id,revision), FOREIGN KEY(content_id) REFERENCES content_items(id), FOREIGN KEY(admin_id) REFERENCES admin_users(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS publish_targets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, content_id BIGINT UNSIGNED NOT NULL, account_id BIGINT UNSIGNED NOT NULL, platform VARCHAR(30) NOT NULL, approved_revision INT UNSIGNED NULL,
 target_status VARCHAR(40) NOT NULL DEFAULT 'DRAFT', remote_post_id VARCHAR(190) NULL, remote_url VARCHAR(2048) NULL, privacy VARCHAR(20) NOT NULL DEFAULT 'private', audience VARCHAR(30) NOT NULL DEFAULT 'not_made_for_kids', synthetic_media TINYINT NOT NULL DEFAULT 0, published_at DATETIME NULL,
 UNIQUE KEY content_destination(content_id,platform), UNIQUE KEY remote_identity(account_id,remote_post_id), UNIQUE KEY target_account(id,account_id), KEY target_status(target_status,published_at),
 FOREIGN KEY(content_id) REFERENCES content_items(id), FOREIGN KEY(account_id,platform) REFERENCES social_accounts(id,platform)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS manual_publish_tasks (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, target_id BIGINT UNSIGNED NOT NULL UNIQUE, method VARCHAR(30) NOT NULL, status VARCHAR(40) NOT NULL DEFAULT 'WAITING_MANUAL_POST',
 source_page_target_id BIGINT UNSIGNED NULL, personal_post_url VARCHAR(2048) NULL, confirmed_by BIGINT UNSIGNED NULL, confirmed_at DATETIME NULL, posted_at DATETIME NULL,
 CONSTRAINT ck_manual_method CHECK(method IN ('SHARE_PAGE','UPLOAD_VIDEO')), FOREIGN KEY(target_id) REFERENCES publish_targets(id), FOREIGN KEY(source_page_target_id) REFERENCES publish_targets(id), FOREIGN KEY(confirmed_by) REFERENCES admin_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS publish_jobs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, target_id BIGINT UNSIGNED NOT NULL, approval_id BIGINT UNSIGNED NOT NULL, operation_type VARCHAR(40) NOT NULL, idempotency_key CHAR(64) NOT NULL UNIQUE,
 status VARCHAR(40) NOT NULL, due_at DATETIME NULL, lease_token_hash CHAR(64) NULL, lease_expires_at DATETIME NULL, next_retry_at DATETIME NULL, attempt_count INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL,
 UNIQUE KEY target_operation(target_id,operation_type), KEY due_jobs(status,due_at), KEY retry_jobs(status,next_retry_at), FOREIGN KEY(target_id) REFERENCES publish_targets(id), FOREIGN KEY(approval_id) REFERENCES content_approvals(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS publish_attempts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, job_id BIGINT UNSIGNED NOT NULL, attempt_no INT NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME NULL, error_category VARCHAR(60) NULL, sanitized_summary TEXT NULL, UNIQUE KEY job_attempt(job_id,attempt_no), FOREIGN KEY(job_id) REFERENCES publish_jobs(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS workflow_logs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, job_id BIGINT UNSIGNED NULL, correlation_id VARCHAR(100) NOT NULL, workflow_key VARCHAR(100) NOT NULL, event_type VARCHAR(60) NOT NULL, severity VARCHAR(20) NOT NULL, sanitized_summary TEXT NULL, created_at DATETIME NOT NULL, KEY correlation(correlation_id), KEY workflow_date(workflow_key,created_at), FOREIGN KEY(job_id) REFERENCES publish_jobs(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS audit_logs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, admin_id BIGINT UNSIGNED NULL, actor_type VARCHAR(20) NOT NULL, action VARCHAR(100) NOT NULL, entity_type VARCHAR(80) NOT NULL, entity_id BIGINT UNSIGNED NULL, details_json LONGTEXT NOT NULL, created_at DATETIME NOT NULL, KEY entity_history(entity_type,entity_id,created_at), KEY audit_date(created_at), FOREIGN KEY(admin_id) REFERENCES admin_users(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS login_attempts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, identity_hash CHAR(64) NOT NULL, ip_hash CHAR(64) NOT NULL, attempted_at DATETIME NOT NULL, KEY attempt_identity(identity_hash,attempted_at), KEY attempt_ip(ip_hash,attempted_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
