param([switch]$Mailpit)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$php = 'C:\xampp\php\php.exe'
$helper = Join-Path $PSScriptRoot 'configure-gmail.php'
if ($Mailpit) {
    & $php $helper --mailpit
    exit $LASTEXITCODE
}
$directory = Join-Path $env:LOCALAPPDATA 'AutoBrands\MailCredentials'
New-Item -ItemType Directory -Path $directory -Force | Out-Null
# Protect the directory before any credential is written. XAMPP must run as this user.
$identity = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$acl = Get-Acl -LiteralPath $directory
$acl.SetAccessRuleProtection($true, $false)
foreach ($principal in @($identity, 'SYSTEM', 'BUILTIN\Administrators')) {
    $rule = New-Object System.Security.AccessControl.FileSystemAccessRule($principal, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')
    $acl.SetAccessRule($rule)
}
Set-Acl -LiteralPath $directory -AclObject $acl
Write-Host 'Account: mohamedipos@gmail.com'
Write-Host 'Activation enables real mail, including pending queue items. No test message is sent.'
$securePassword = Read-Host 'Enter the NEW Google app password (hidden)' -AsSecureString
$pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
try {
    $password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    $inputData = @{ password = $password; directory = $directory } | ConvertTo-Json -Compress
    $inputData | & $php $helper
    $result = $LASTEXITCODE
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    $password = $null
    $inputData = $null
    $securePassword.Dispose()
}
exit $result
