"""Create the shared-hosting handoff ZIP. Explicitly exclude all secrets/runtime data."""
import hashlib
from pathlib import Path
import zipfile

ROOT = Path(__file__).resolve().parents[1]
destination = ROOT / 'dist'
destination.mkdir(exist_ok=True)
archive_path = destination / 'Hybrid-AI-Auto-Post-Studio-Phase2-RC1.zip'
with zipfile.ZipFile(archive_path, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for file in sorted((ROOT/'public').rglob('*')):
        if file.is_file():
            archive.write(file, 'studio/' + file.relative_to(ROOT/'public').as_posix())
    private = ROOT/'studio-private'
    for file in sorted(private.rglob('*')):
        if not file.is_file():
            continue
        rel = file.relative_to(private)
        if (rel.parts[0].startswith('config') and rel.suffix == '.php' and rel.name != 'config.example.php') or (rel.parts[0] == 'var' and rel.name != '.gitkeep'):
            continue
        archive.write(file, 'studio-private/' + rel.as_posix())
    for file in sorted((ROOT/'docs').rglob('*')):
        if file.is_file() and file.suffix in ['.md', '.jpg', '.json']:
            archive.write(file, 'docs/' + file.relative_to(ROOT/'docs').as_posix())
    archive.write(ROOT/'README.md','README.md')
with zipfile.ZipFile(archive_path) as archive:
    names = archive.namelist()
    assert 'studio/index.php' in names
    assert 'studio-private/config.php' not in names
    assert not any('application.key' in name or 'test-secrets' in name or '.local' in name or '.tools' in name for name in names)
    assert any(name.startswith('studio-private/vendor/licenses/') for name in names)
    assert archive.testzip() is None
digest = hashlib.sha256(archive_path.read_bytes()).hexdigest()
(destination / (archive_path.name + '.sha256')).write_text(digest + '  ' + archive_path.name + '\n',encoding='ascii')
print('Package:',archive_path)
print('Files:',len(names),'Bytes:',archive_path.stat().st_size)
print('SHA256:',digest)
print('Verified: no local config, secrets, runtime or test media in archive')

upgrade_path=destination/'Hybrid-AI-Auto-Post-Studio-Phase2-Upgrade-RC1.zip'
with zipfile.ZipFile(archive_path) as full, zipfile.ZipFile(upgrade_path,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as upgrade:
    for name in full.namelist():
        if name.startswith(('studio/','studio-private/app/','studio-private/views/','studio-private/database/','studio-private/workflows/','docs/')) or name=='README.md':
            upgrade.writestr(name,full.read(name))
with zipfile.ZipFile(upgrade_path) as upgrade:
    assert not any(name.startswith('studio-private/var/') or name.endswith('/config.php') or name.endswith('/config.example.php') for name in upgrade.namelist())
    assert upgrade.testzip() is None
digest=hashlib.sha256(upgrade_path.read_bytes()).hexdigest()
(destination/(upgrade_path.name+'.sha256')).write_text(digest+'  '+upgrade_path.name+'\n',encoding='ascii')
print('Upgrade package:',upgrade_path,'Bytes:',upgrade_path.stat().st_size)
