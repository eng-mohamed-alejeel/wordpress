Set-StrictMode -Version Latest
$binary = Join-Path $env:LOCALAPPDATA 'AutoBrands\Mailpit\mailpit.exe'
Get-Process -Name mailpit -ErrorAction SilentlyContinue | Where-Object Path -EQ $binary | Stop-Process
Write-Output 'Local AutoBrands Mailpit stopped. Stored messages are retained.'
