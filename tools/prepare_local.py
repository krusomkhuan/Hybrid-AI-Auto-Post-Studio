"""Create a LOCAL-ONLY test configuration and fixture database on port 33079."""
import json
from pathlib import Path
import secrets
import subprocess

root = Path(__file__).resolve().parents[1]
local = root / '.local'
local.mkdir(exist_ok=True)
config = root / 'studio-private' / 'config.php'
if config.exists():
    raise SystemExit('Refusing to overwrite existing config.php; reuse the local environment or move your config yourself.')
subprocess.run([str(root / '.tools/mariadb/bin/mariadb.exe'), '--no-defaults', '--host=127.0.0.1', '--port=33079', '--user=root', '-e', 'CREATE DATABASE IF NOT EXISTS studio_phase1_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'], check=True)
data = {'email': 'admin@example.test', 'password': secrets.token_urlsafe(24), 'setup_key': secrets.token_urlsafe(36)}
(local / 'test-secrets.json').write_text(json.dumps(data), encoding='utf-8')
template = (root / 'studio-private/config.example.php').read_text(encoding='utf-8')
template = template.replace("'production'", "'local'").replace('https://www.krusomkhuan.com/studio', 'http://127.0.0.1:8097/studio')
template = template.replace("'host' => 'localhost', 'port' => 3306", "'host' => '127.0.0.1', 'port' => 33079")
template = template.replace('CHANGE_DATABASE', 'studio_phase1_test').replace('CHANGE_USER', 'root').replace('CHANGE_PASSWORD', '').replace('CHANGE_TO_A_RANDOM_SECRET_AT_LEAST_32_CHARACTERS', data['setup_key'])
template = template.replace("__DIR__ . '/var/media'", "'" + str(local / 'media').replace('\\', '/') + "'")
config.write_text(template, encoding='utf-8')
(local / 'router.php').write_text('''<?php
$public = dirname(__DIR__) . '/public';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/studio/assets/')) {
    $file = realpath($public . substr($path, strlen('/studio')));
    $assets = realpath($public . '/assets') . DIRECTORY_SEPARATOR;
    if ($file && str_starts_with($file, $assets) && is_file($file)) {
        header('Content-Type: ' . (str_ends_with($file, '.css') ? 'text/css' : (str_ends_with($file, '.js') ? 'text/javascript' : 'text/plain')));
        readfile($file); return true;
    }
    http_response_code(404); return true;
}
if ($path === '/studio' || $path === '/studio/' || $path === '/studio/index.php') {
    putenv('STUDIO_PRIVATE_ROOT=' . dirname(__DIR__) . '/studio-private');
    require $public . '/index.php'; return true;
}
http_response_code(404); echo 'Not found';
''', encoding='utf-8')
print('Local test config ready; no production credentials created or used.')
