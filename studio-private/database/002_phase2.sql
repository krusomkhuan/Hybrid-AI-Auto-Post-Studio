CREATE TABLE IF NOT EXISTS oauth_states (
 state_hash CHAR(64) PRIMARY KEY, admin_id BIGINT UNSIGNED NOT NULL, provider VARCHAR(20) NOT NULL,
 encrypted_verifier TEXT NULL, session_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL,
 FOREIGN KEY(admin_id) REFERENCES admin_users(id), KEY state_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS publish_transfers (
 job_id BIGINT UNSIGNED PRIMARY KEY, stage VARCHAR(40) NOT NULL DEFAULT 'NEW', encrypted_session TEXT NULL,
 remote_id VARCHAR(190) NULL, acknowledged_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
 last_error_code VARCHAR(80) NULL, last_error_message VARCHAR(255) NULL, updated_at DATETIME NOT NULL,
 FOREIGN KEY(job_id) REFERENCES publish_jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS integration_events (
 event_id VARCHAR(100) PRIMARY KEY, payload_hash CHAR(64) NOT NULL, result_json TEXT NULL,
 status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, KEY event_date(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS request_nonces (
 nonce_hash CHAR(64) PRIMARY KEY, expires_at DATETIME NOT NULL, KEY nonce_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
