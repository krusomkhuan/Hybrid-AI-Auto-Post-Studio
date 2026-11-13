"""Download official, free dependencies for development/package authoring only."""
import hashlib
import io
import json
from pathlib import Path
import urllib.request
import zipfile

ROOT = Path(__file__).resolve().parents[1]

def get(url):
    req = urllib.request.Request(url, headers={"User-Agent": "Hybrid-Studio-Phase1"})
    with urllib.request.urlopen(req, timeout=90) as response:
        return response.read()

def main():
    dev = ROOT / '.tools'
    dev.mkdir(exist_ok=True)
    release = json.loads(get('https://windows.php.net/downloads/releases/releases.json'))['8.4']['nts-vs17-x64']['zip']
    if not (dev / 'php' / 'php.exe').exists():
        data = get('https://windows.php.net/downloads/releases/' + release['path'])
        assert hashlib.sha256(data).hexdigest() == release['sha256'], 'PHP checksum mismatch'
        with zipfile.ZipFile(io.BytesIO(data)) as archive:
            archive.extractall(dev / 'php')
        print('PHP runtime: checksum verified')
    public = ROOT / 'public' / 'assets' / 'vendor'
    public.mkdir(parents=True, exist_ok=True)
    for filename in ['css/bootstrap.min.css', 'js/bootstrap.bundle.min.js']:
        dest = public / Path(filename).name
        if not dest.exists():
            dest.write_bytes(get('https://cdn.jsdelivr.net/npm/bootstrap@5.3.9/dist/' + filename))
    license_file = public / 'BOOTSTRAP-LICENSE.txt'
    if not license_file.exists():
        license_file.write_bytes(get('https://raw.githubusercontent.com/twbs/bootstrap/v5.3.9/LICENSE'))
    vendor = ROOT / 'studio-private' / 'vendor'
    vendor.mkdir(parents=True, exist_ok=True)
    if not (vendor / 'getid3' / 'getid3.php').exists() or not (vendor / 'licenses').exists():
        info = json.loads(get('https://api.github.com/repos/JamesHeinrich/getID3/releases/tags/v1.9.28'))
        tag = info['tag_name']
        data = get('https://api.github.com/repos/JamesHeinrich/getID3/zipball/' + tag)
        with zipfile.ZipFile(io.BytesIO(data)) as archive:
            for member in archive.infolist():
                parts = Path(member.filename).parts[1:]
                if not parts or member.is_dir():
                    continue
                if parts[0] in ['getid3', 'licenses'] or parts[-1].lower().startswith(('license', 'copying')):
                    dest = vendor.joinpath(*parts)
                    dest.parent.mkdir(parents=True, exist_ok=True)
                    dest.write_bytes(archive.read(member))
        (vendor / 'getid3-version.json').write_text(json.dumps({'tag': tag, 'sha256': hashlib.sha256(data).hexdigest(), 'source': info['html_url']}, indent=2))
        print('getID3:', tag)
    print('Dependencies ready (not installed on hosting)')

if __name__ == '__main__':
    main()
