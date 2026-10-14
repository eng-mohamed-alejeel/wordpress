param([switch]$Install)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$directory = Join-Path $env:LOCALAPPDATA 'AutoBrands\Mailpit'
$binary = Join-Path $directory 'mailpit.exe'
New-Item -ItemType Directory -Path $directory -Force | Out-Null
if (-not (Test-Path -LiteralPath $binary)) {
    if (-not $Install) { throw 'Run tools/start-mailpit.ps1 -Install first.' }
    $release = Invoke-RestMethod 'https://api.github.com/repos/axllent/mailpit/releases/tags/v1.31.5'
    $asset = $release.assets | Where-Object name -EQ 'mailpit-windows-amd64.zip'
    $archive = Join-Path $directory 'mailpit.zip'
    Invoke-WebRequest -Uri $asset.browser_download_url -OutFile $archive -UseBasicParsing
    $actual = (Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($asset.digest -ne ('sha256:' + $actual)) { throw 'Mailpit SHA256 verification failed.' }
    Expand-Archive -LiteralPath $archive -DestinationPath $directory -Force
}
$running = Get-NetTCPConnection -LocalPort 8025 -State Listen -ErrorAction SilentlyContinue
if ($running) {
    $process = Get-Process -Id $running[0].OwningProcess
    if ($process.Path -ne $binary) { throw 'Port 8025 belongs to another application.' }
    Write-Output 'Mailpit is already running: http://127.0.0.1:8025'
    exit
}
if (Get-NetTCPConnection -LocalPort 1025 -State Listen -ErrorAction SilentlyContinue) { throw 'SMTP port 1025 is already in use.' }
$database = Join-Path $directory 'messages.db'
Start-Process -FilePath $binary -ArgumentList '--listen=127.0.0.1:8025', '--smtp=127.0.0.1:1025', ('--database="' + $database + '"'), '--max=500' -WindowStyle Hidden -RedirectStandardOutput (Join-Path $directory 'stdout.log') -RedirectStandardError (Join-Path $directory 'stderr.log')
Write-Output 'Mailpit started: http://127.0.0.1:8025'
