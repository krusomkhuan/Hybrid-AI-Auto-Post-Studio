<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$private = 'C:/xampp/studio-private';
if (is_file($private . '/config.php')) { throw new RuntimeException('Local config already exists; refusing to replace it.'); }
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$database = 'studio_local_phase1';
if ($pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='studio_local_phase1'")->fetchColumn()) { throw new RuntimeException('Local database exists; refusing to overwrite it.'); }
$dbUser = 'studio_p1_' . bin2hex(random_bytes(3));
$dbPassword = bin2hex(random_bytes(24));
$setupKey = bin2hex(random_bytes(24));
$pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('CREATE USER ' . $pdo->quote($dbUser) . "@'127.0.0.1' IDENTIFIED BY " . $pdo->quote($dbPassword));
$pdo->exec('GRANT ALL PRIVILEGES ON `' . $database . '`.* TO ' . $pdo->quote($dbUser) . "@'127.0.0.1'");
$config = [
    'environment'=>'local',
    'app_url'=>'http://localhost/studio',
    'db'=>['host'=>'127.0.0.1','port'=>3306,'name'=>$database,'user'=>$dbUser,'password'=>$dbPassword],
    'setup_key'=>$setupKey,
    'storage_path'=>$private . '/var/media',
    'storage_budget_bytes'=>30 * 1024 * 1024 * 1024,
    'max_upload_bytes'=>200 * 1024 * 1024,
    'min_duration_seconds'=>30,
    'max_duration_seconds'=>60,
    'session_idle_minutes'=>60,
];
$newPdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$database.';charset=utf8mb4',$dbUser,$dbPassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$newPdo->query('SELECT 1');
file_put_contents($private . '/config.php', "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config,true) . ";\n", LOCK_EX);
file_put_contents($private . '/LOCAL-SETUP-KEY.txt', "Local setup only: http://localhost/studio/\n\nSetup Key:\n" . $setupKey . "\n\nOpen the URL and create your own admin account. Do not upload this local config or key to hosting.\n", LOCK_EX);
echo "Local config created. Dedicated database: studio_local_phase1. Connection verified.\n";
echo "Setup Key saved privately to C:/xampp/studio-private/LOCAL-SETUP-KEY.txt (not printed).\n";
