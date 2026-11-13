$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$xamppRoot = 'C:\xampp'
$webRoot = 'C:\xampp\htdocs'
$targetStudio = 'C:\xampp\htdocs\studio'
$privateRoot = 'C:\xampp\studio-private'

# These are explicitly named installation paths. Never move computed arbitrary paths.
if ((Resolve-Path -LiteralPath $webRoot).Path -ne $webRoot) { throw 'Unexpected XAMPP Document Root' }
if ([IO.Path]::GetFullPath($targetStudio) -ne 'C:\xampp\htdocs\studio') { throw 'Unexpected Studio target' }
if (Test-Path -LiteralPath $privateRoot) { throw 'Private installation exists; refusing to replace it.' }
if ((Test-Path -LiteralPath $targetStudio) -and ((Get-Item -LiteralPath $targetStudio).Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'Studio target is a link; manual inspection required.' }

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = Join-Path $xamppRoot ('studio-backups\' + $stamp)
New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null
Copy-Item -LiteralPath 'C:\xampp\php\php.ini' -Destination (Join-Path $backupRoot 'php.ini')
if (Test-Path -LiteralPath $targetStudio) {
    $resolvedStudio = (Resolve-Path -LiteralPath $targetStudio).Path
    $backupStudio = Join-Path $backupRoot 'studio-original'
    if ($resolvedStudio -ne 'C:\xampp\htdocs\studio' -or -not $backupStudio.StartsWith('C:\xampp\studio-backups\')) { throw 'Backup path validation failed' }
    Move-Item -LiteralPath $resolvedStudio -Destination $backupStudio
}
New-Item -ItemType Directory -Path $targetStudio, $privateRoot -Force | Out-Null
Get-ChildItem -LiteralPath (Join-Path $projectRoot 'public') -Force | ForEach-Object { Copy-Item -LiteralPath $_.FullName -Destination $targetStudio -Recurse }
foreach ($folder in @('app','database','vendor','views')) {
    Copy-Item -LiteralPath (Join-Path $projectRoot ('studio-private\' + $folder)) -Destination $privateRoot -Recurse
}
New-Item -ItemType Directory -Path (Join-Path $privateRoot 'var') -Force | Out-Null
Copy-Item -LiteralPath (Join-Path $projectRoot 'studio-private\.htaccess') -Destination $privateRoot

$phpIni = [IO.File]::ReadAllText('C:\xampp\php\php.ini')
$phpIni = [regex]::Replace($phpIni, '(?m)^;extension=sodium\s*$', 'extension=sodium')
[IO.File]::WriteAllText('C:\xampp\php\php.ini', $phpIni, [Text.UTF8Encoding]::new($false))

# Keep the original Apache configuration unchanged. This local copy binds HTTP only to loopback.
$httpd = [IO.File]::ReadAllText('C:\xampp\apache\conf\httpd.conf')
$httpd = [regex]::Replace($httpd, '(?m)^Listen 80\s*$', 'Listen 127.0.0.1:80')
$httpd = [regex]::Replace($httpd, '(?m)^Include conf/extra/httpd-ssl\.conf\s*$', '# SSL is unused in the isolated localhost configuration')
$httpd = [regex]::Replace($httpd, '(?m)^Include "conf/extra/httpd-ajp\.conf"\s*$', '# AJP is unused in the isolated localhost configuration')
[IO.File]::WriteAllText((Join-Path $privateRoot 'local-httpd.conf'), $httpd, [Text.UTF8Encoding]::new($false))
Write-Output "Local application files ready. Original files backed up to $backupRoot"
Write-Output 'Production config and keys were not copied into the local application.'
