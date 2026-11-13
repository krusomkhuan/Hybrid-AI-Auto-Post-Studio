"""Prepare isolated local MySQL-compatible integration test tools. Never deploy this."""
import io
import os
from pathlib import Path
import urllib.request
import zipfile

ROOT = Path(__file__).resolve().parents[1]
runtime = ROOT / '.tools'
runtime.mkdir(exist_ok=True)
target = runtime / 'mariadb'
if not (target / 'bin' / 'mariadbd.exe').exists():
    url = 'https://archive.mariadb.org/mariadb-11.4.9/winx64-packages/mariadb-11.4.9-winx64.zip'
    with urllib.request.urlopen(url, timeout=120) as response:
        data = response.read()
    with zipfile.ZipFile(io.BytesIO(data)) as archive:
        for member in archive.infolist():
            parts = Path(member.filename).parts[1:]
            if not parts or member.is_dir():
                continue
            path = target.joinpath(*parts)
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(archive.read(member))
    print('MariaDB 11.4.9 portable prepared for loopback tests')
php = runtime / 'php'
(php / 'php.ini').write_text('extension_dir="' + str(php / 'ext').replace('\\', '/') + '"\n' + '\n'.join('extension=' + name for name in ['pdo_mysql','mbstring','fileinfo','sodium','openssl','curl']) + '\ndate.timezone=UTC\nupload_max_filesize=200M\npost_max_size=205M\nmemory_limit=256M\n', encoding='utf-8')
print('Local PHP extensions configured')
