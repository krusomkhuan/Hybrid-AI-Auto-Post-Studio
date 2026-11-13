"""Read-only pre-publish audit. Reports paths, never secret values."""
import json
from pathlib import Path
import re
import subprocess

ROOT=Path(__file__).resolve().parents[1]
allowed=['README.md','.gitignore','.gitattributes','public','studio-private','docs','tests','tools']
files=[]
for name in allowed:
    path=ROOT/name
    for file in ([path] if path.is_file() else path.rglob('*')):
        if not file.is_file():continue
        rel=file.relative_to(ROOT)
        if '__pycache__' in rel.parts:continue
        if rel.parts[0]=='studio-private':
            if len(rel.parts)>1 and rel.parts[1]=='var' and file.name!='.gitkeep':continue
            if file.parent==ROOT/'studio-private' and file.name.startswith('config') and file.name!='config.example.php':continue
        files.append(file)
secrets=[]
php=Path('C:/xampp/php/php.exe')
for path in [ROOT/'studio-private/config.php',Path('C:/xampp/studio-private/config.php')]:
    if not path.exists():continue
    code='$c=require '+json.dumps(str(path).replace('\\','/'))+';echo json_encode($c);'
    result=subprocess.run([str(php),'-r',code],capture_output=True,text=True,encoding='utf-8',check=True)
    data=json.loads(result.stdout)
    def collect(value):
        if not isinstance(value,dict):return
        for k,v in value.items():
            if isinstance(v,dict):collect(v)
            elif any(x in k.lower() for x in ['password','secret','token','setup_key']) and isinstance(v,str) and len(v)>=8 and not v.startswith('CHANGE_'):
                secrets.append(v.encode())
    collect(data)
patterns=[rb'gh[pousr]_[A-Za-z0-9]{30,}',rb'github_pat_[A-Za-z0-9_]{30,}',rb'AIza[A-Za-z0-9_-]{30,}',rb'GOCSPX-[A-Za-z0-9_-]{20,}',rb'AKIA[A-Z0-9]{16}',rb'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----']
errors=[]
for file in files:
    content=file.read_bytes()
    if any(secret in content for secret in secrets):errors.append(str(file.relative_to(ROOT))+' (configured secret)')
    if any(re.search(pattern,content) for pattern in patterns):errors.append(str(file.relative_to(ROOT))+' (credential pattern)')
if errors:raise SystemExit('STOP: review sensitive files:\n'+'\n'.join(errors))
print('PASS: '+str(len(files))+' publishable files scanned; no configured secrets or credential patterns detected.')
print('Excluded: actual config, var/media/key, Database notes, local runtime, backups and dist archives.')
