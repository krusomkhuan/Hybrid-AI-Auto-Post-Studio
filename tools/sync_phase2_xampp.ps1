$ErrorActionPreference='Stop'
$projectRoot=Split-Path $PSScriptRoot -Parent
$publicTarget='C:\xampp\htdocs\studio'
$privateTarget='C:\xampp\studio-private'
if((Resolve-Path -LiteralPath $publicTarget).Path -ne 'C:\xampp\htdocs\studio'){throw 'Unexpected public target'}
if((Resolve-Path -LiteralPath $privateTarget).Path -ne 'C:\xampp\studio-private'){throw 'Unexpected private target'}
# Config, var, encryption key, media and the existing vendor tree are deliberately preserved.
foreach($folder in @('app','views','database','workflows')){
    $dest=Join-Path $privateTarget $folder
    New-Item -ItemType Directory -Path $dest -Force | Out-Null
    Get-ChildItem -LiteralPath (Join-Path $projectRoot ('studio-private\'+$folder)) -File | Sort-Object @{Expression={$_.Name -eq 'bootstrap.php'}},Name | ForEach-Object {Copy-Item -LiteralPath $_.FullName -Destination $dest -Force}
}
Get-ChildItem -LiteralPath (Join-Path $projectRoot 'public') -Force | ForEach-Object {Copy-Item -LiteralPath $_.FullName -Destination $publicTarget -Recurse -Force}
& 'C:\xampp\php\php.exe' (Join-Path $projectRoot 'tools\upgrade_phase2_xampp.php')
if($LASTEXITCODE -ne 0){throw 'Schema upgrade failed; inspect backup and logs before continuing'}
Write-Output 'Updated only Studio application files in XAMPP.'
