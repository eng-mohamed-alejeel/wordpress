param()
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$pluginRoot = Join-Path $repo 'wp-content/plugins/auto-dealership-core'
$themeRoot = Join-Path $repo 'wp-content/themes/car-dealer'
$pluginHeader = [IO.File]::ReadAllText((Join-Path $pluginRoot 'auto-dealership-core.php'))
$themeHeader = [IO.File]::ReadAllText((Join-Path $themeRoot 'style.css'))
$pluginVersion = [regex]::Match($pluginHeader, '(?m)^\s*\* Version:\s*(\S+)').Groups[1].Value
$themeVersion = [regex]::Match($themeHeader, '(?m)^Version:\s*(\S+)').Groups[1].Value
if (-not $pluginVersion -or $pluginVersion -ne $themeVersion) { throw 'Plugin and theme versions must match.' }
$readmeTag = [regex]::Match([IO.File]::ReadAllText((Join-Path $pluginRoot 'readme.txt')), '(?m)^Stable tag:\s*(\S+)').Groups[1].Value
if ($readmeTag -ne $pluginVersion) { throw 'Plugin readme stable tag must match the release.' }
$themeReadmeTag = [regex]::Match([IO.File]::ReadAllText((Join-Path $themeRoot 'readme.txt')), '(?m)^Version:\s*(\S+)').Groups[1].Value
if ($themeReadmeTag -ne $themeVersion) { throw 'Theme readme version must match the release.' }
$pluginCompatibility = [IO.File]::ReadAllText((Join-Path $pluginRoot 'resources/release.json')) | ConvertFrom-Json
$themeCompatibility = [IO.File]::ReadAllText((Join-Path $themeRoot 'inc/release.json')) | ConvertFrom-Json
foreach ($release in @($pluginCompatibility,$themeCompatibility)) {
    if ($release.format -ne 1 -or $release.product -ne 'auto-dealership' -or $release.contract -ne 1 -or $release.version -ne $pluginVersion) { throw 'Missing or inconsistent compatibility metadata.' }
    if ([version]$release.partner_min -ge [version]$release.partner_before) { throw 'Invalid partner compatibility range.' }
}
$schemaText = [IO.File]::ReadAllText((Join-Path $pluginRoot 'src/Database/Schema.php'))
$schemaVersion = [regex]::Match($schemaText, "public const VERSION = '([^']+)'").Groups[1].Value
if ($pluginCompatibility.schema_target -ne $schemaVersion) { throw 'Release database target does not match the installer.' }
$output = Join-Path $repo ('.tmp/product-release/' + $pluginVersion + '-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
if (Test-Path -LiteralPath $output) { throw 'Output already exists.' }
New-Item -ItemType Directory -Path $output | Out-Null
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$artifacts = @()
foreach ($component in @(@{root=$pluginRoot;slug='auto-dealership-core';dirs=@('src','assets','languages','resources','bin');files=@('auto-dealership-core.php','readme.txt')}, @{root=$themeRoot;slug='car-dealer';dirs=@('assets','inc','languages','templates');files=@()})) {
    $files = @()
    foreach ($dir in $component.dirs) { $files += @(Get-ChildItem -LiteralPath (Join-Path $component.root $dir) -Recurse -File) }
    if ($component.slug -eq 'car-dealer') { $files += @(Get-ChildItem -LiteralPath $component.root -File | Where-Object { $_.Extension -eq '.php' -or $_.Name -in @('style.css','readme.txt','screenshot.png') }) }
    else { foreach ($name in $component.files) { $files += Get-Item -LiteralPath (Join-Path $component.root $name) } }
    $archivePath = Join-Path $output ($component.slug + '-' + $pluginVersion + '.zip')
    $archive = [IO.Compression.ZipFile]::Open($archivePath, [IO.Compression.ZipArchiveMode]::Create)
    $manifestFiles = @()
    try {
        foreach ($file in ($files | Sort-Object FullName -Unique)) {
            $relative = $file.FullName.Substring($component.root.Length + 1).Replace([char]92,[char]47)
            if ($relative -match '(^|/)(tests?|node_modules|\.git|\.tmp|uploads|cache)(/|$)|\.(sql|log|bak|env|xlsx|csv|zip)$|(^|/)(wp-config|gmail)\.php$') { throw "Forbidden file in runtime: $relative" }
            $entry = $component.slug + '/' + $relative
            [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $file.FullName, $entry, [IO.Compression.CompressionLevel]::Optimal) | Out-Null
            $manifestFiles += [ordered]@{path=$entry;bytes=$file.Length;sha256=(Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()}
        }
    } finally { $archive.Dispose() }
    $check = [IO.Compression.ZipFile]::OpenRead($archivePath)
    try { if ($check.Entries.Count -ne $manifestFiles.Count) { throw 'Archive entry count mismatch.' } } finally { $check.Dispose() }
    $artifacts += [ordered]@{name=[IO.Path]::GetFileName($archivePath);sha256=(Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant();file_count=$manifestFiles.Count;files=$manifestFiles}
}
$head = (& git -C $repo rev-parse HEAD).Trim()
$dirty = @(& git -C $repo status --porcelain --untracked-files=all -- wp-content/plugins/auto-dealership-core wp-content/themes/car-dealer)
$manifest = [ordered]@{product='auto-dealership';version=$pluginVersion;built_at_utc=[DateTime]::UtcNow.ToString('o');source_commit=$head;working_tree_changes=($dirty.Count -gt 0);contains_database=$false;contains_credentials=$false;artifacts=$artifacts}
[IO.File]::WriteAllText((Join-Path $output 'manifest.json'), ($manifest | ConvertTo-Json -Depth 8), [Text.UTF8Encoding]::new($false))
Copy-Item -LiteralPath (Join-Path $repo 'docs/PRODUCT-INSTALLATION-AR.md') -Destination (Join-Path $output 'INSTALLATION-AR.md')
Copy-Item -LiteralPath (Join-Path $repo 'docs/PORTABLE-JOB-RUNTIME-AR.md') -Destination (Join-Path $output 'PORTABLE-JOB-RUNTIME-AR.md')
Write-Output $output
foreach ($artifact in $artifacts) { Write-Output ($artifact.name + ' SHA256=' + $artifact.sha256 + ' files=' + $artifact.file_count) }
