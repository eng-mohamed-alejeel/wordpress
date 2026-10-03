Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repo = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$paths = @('wp-content/plugins/auto-dealership-core', 'wp-content/themes/car-dealer')
$runtimePaths = @(
    'wp-content/plugins/auto-dealership-core/assets',
    'wp-content/plugins/auto-dealership-core/auto-dealership-core.php',
    'wp-content/plugins/auto-dealership-core/readme.txt',
    'wp-content/plugins/auto-dealership-core/src',
    'wp-content/themes/car-dealer/assets',
    'wp-content/themes/car-dealer/inc',
    'wp-content/themes/car-dealer/templates',
    'wp-content/themes/car-dealer/archive-car.php',
    'wp-content/themes/car-dealer/archive-car_offer.php',
    'wp-content/themes/car-dealer/footer.php',
    'wp-content/themes/car-dealer/functions.php',
    'wp-content/themes/car-dealer/header.php',
    'wp-content/themes/car-dealer/index.php',
    'wp-content/themes/car-dealer/page-about.php',
    'wp-content/themes/car-dealer/page-contact.php',
    'wp-content/themes/car-dealer/page-privacy.php',
    'wp-content/themes/car-dealer/page-terms.php',
    'wp-content/themes/car-dealer/page.php',
    'wp-content/themes/car-dealer/readme.txt',
    'wp-content/themes/car-dealer/single-car.php',
    'wp-content/themes/car-dealer/single-car_offer.php',
    'wp-content/themes/car-dealer/style.css'
)
$head = (& git -C $repo rev-parse HEAD).Trim()
if ($LASTEXITCODE -ne 0 -or $head -notmatch '^[0-9a-f]{40}$') { throw 'Cannot identify the release commit.' }

$changes = @(& git -C $repo status --porcelain --untracked-files=all -- $paths)
if ($LASTEXITCODE -ne 0 -or $changes.Count -ne 0) { throw 'Plugin or theme files differ from the release commit.' }
$trackedConfig = @(& git -C $repo ls-tree --name-only HEAD -- wp-config.php)
if ($LASTEXITCODE -ne 0 -or $trackedConfig.Count -ne 0) { throw 'The release commit still contains wp-config.php.' }
$indexedConfig = @(& git -C $repo ls-files -- wp-config.php)
if ($LASTEXITCODE -ne 0 -or $indexedConfig.Count -ne 0) { throw 'The Git index still contains wp-config.php.' }

$allFiles = @(& git -C $repo ls-tree -r --name-only HEAD -- $paths)
$trackedFiles = @(& git -C $repo ls-tree -r --name-only HEAD -- $runtimePaths)
if ($LASTEXITCODE -ne 0 -or $trackedFiles.Count -eq 0) { throw 'Cannot list runtime files.' }
$runtimeSet = @{}
foreach ($path in $trackedFiles) { $runtimeSet[$path] = $true }
foreach ($path in $allFiles) {
    if ($runtimeSet.ContainsKey($path)) { continue }
    if ($path -match '^wp-content/plugins/auto-dealership-core/tests/' -or
        $path -in @('wp-content/themes/car-dealer/build-css.js', 'wp-content/themes/car-dealer/merge-css.ps1')) { continue }
    throw "Unclassified file in release roots: $path"
}

$pluginFile = Join-Path $repo 'wp-content/plugins/auto-dealership-core/auto-dealership-core.php'
$themeFile = Join-Path $repo 'wp-content/themes/car-dealer/style.css'
$pluginHeader = Get-Content -LiteralPath $pluginFile -Raw
$themeHeader = Get-Content -LiteralPath $themeFile -Raw
$pluginMatch = [regex]::Match($pluginHeader, '(?m)^\s*\*\s*Version:\s*([^\r\n]+)')
$themeMatch = [regex]::Match($themeHeader, '(?m)^Version:\s*([^\r\n]+)')
if (-not $pluginMatch.Success -or -not $themeMatch.Success) { throw 'A release version is missing.' }
$version = $pluginMatch.Groups[1].Value.Trim()
if ($version -ne $themeMatch.Groups[1].Value.Trim()) { throw 'Plugin and theme versions differ.' }

$output = Join-Path $repo '.tmp/release-packages'
New-Item -ItemType Directory -Path $output -Force | Out-Null
$name = 'auto-dealership-{0}-{1}.zip' -f $version, $head.Substring(0, 12)
$archivePath = Join-Path $output $name
$manifestPath = Join-Path $output ($name + '.manifest.json')
if ((Test-Path -LiteralPath $archivePath) -or (Test-Path -LiteralPath $manifestPath)) {
    throw 'This commit already has a local package; refusing to overwrite it.'
}

& git -C $repo archive --format=zip "--output=$archivePath" HEAD -- $runtimePaths
if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $archivePath)) { throw 'Git archive failed.' }

Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [System.IO.Compression.ZipFile]::OpenRead($archivePath)
$files = @()
$required = @(
    'wp-content/plugins/auto-dealership-core/auto-dealership-core.php',
    'wp-content/themes/car-dealer/style.css'
)
$seen = @{}
try {
    foreach ($entry in $archive.Entries) {
        $path = $entry.FullName
        if ($path.EndsWith('/')) { continue }
        if ($path -notmatch '^wp-content/(plugins/auto-dealership-core|themes/car-dealer)/' -or
            $path -match '(^|/)\.\.(/|$)' -or $path -match '(^|/)wp-config\.php$' -or
            $path -match '^wp-content/plugins/auto-dealership-core/tests/' -or
            $path -in @('wp-content/themes/car-dealer/build-css.js', 'wp-content/themes/car-dealer/merge-css.ps1') -or
            $seen.ContainsKey($path)) { throw "Unsafe or duplicate archive entry: $path" }
        $seen[$path] = $true
        $stream = $entry.Open()
        $sha = [System.Security.Cryptography.SHA256]::Create()
        try {
            $digest = [BitConverter]::ToString($sha.ComputeHash($stream)).Replace('-', '').ToLowerInvariant()
        } finally {
            $stream.Dispose()
            $sha.Dispose()
        }
        $files += [ordered]@{ path = $path; bytes = $entry.Length; sha256 = $digest }
    }
} finally {
    $archive.Dispose()
}
foreach ($path in $required) { if (-not $seen.ContainsKey($path)) { throw "Required file missing: $path" } }

if ($files.Count -ne $trackedFiles.Count) {
    throw 'Archive file count does not match the committed tree.'
}
foreach ($path in $trackedFiles) {
    if (-not $seen.ContainsKey($path)) { throw "Committed file missing from archive: $path" }
}
$manifest = [ordered]@{
    package = $name
    package_sha256 = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant()
    source_commit = $head
    plugin_version = $version
    theme_version = $version
    file_count = $files.Count
    files = $files
}
$manifest | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $manifestPath -Encoding UTF8
Write-Output "Package: $archivePath"
Write-Output "Manifest: $manifestPath"
Write-Output "Commit: $head"
Write-Output "Version: $version; files: $($files.Count); SHA-256: $($manifest.package_sha256)"
