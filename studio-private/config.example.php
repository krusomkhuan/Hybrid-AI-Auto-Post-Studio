<?php
declare(strict_types=1);

// Copy to config.php outside the website Document Root. Never upload secrets to public/.
return [
    'environment' => 'production',
    'app_url' => 'https://www.krusomkhuan.com/studio',
    'db' => ['host' => 'localhost', 'port' => 3306, 'name' => 'CHANGE_DATABASE', 'user' => 'CHANGE_USER', 'password' => 'CHANGE_PASSWORD'],
    // Set your own random secret (at least 32 characters); remove it after installation.
    'setup_key' => 'CHANGE_TO_A_RANDOM_SECRET_AT_LEAST_32_CHARACTERS',
    'storage_path' => __DIR__ . '/var/media',
    // A limit for this application, NOT a reading of your hosting account quota.
    'storage_budget_bytes' => 30 * 1024 * 1024 * 1024,
    'max_upload_bytes' => 200 * 1024 * 1024,
    'min_duration_seconds' => 30,
    'max_duration_seconds' => 60,
    'session_idle_minutes' => 60,
];

