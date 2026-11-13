param(
    [string]$OutputDirectory = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repo = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    $OutputDirectory = Join-Path $repo 'release-packages'
} else {
    $OutputDirectory = [System.IO.Path]::GetFullPath($OutputDirectory)
}

$pluginRoot = 'wp-content/plugins/auto-dealership-core'
$themeRoot = 'wp-content/themes/car-dealer'
$muFiles = @(
    'wp-content/mu-plugins/adc-typography.php',
    'wp-content/mu-plugins/adc-mail.php',
    'wp-content/mu-plugins/adc-password-email.php'
)
$releaseRoots = @($pluginRoot, $themeRoot) + $muFiles

$status = @(& git -C $repo status --porcelain --untracked-files=all -- $releaseRoots)
if ($LASTEXITCODE -ne 0) { throw 'Cannot inspect the release working tree.' }
if ($status.Count -ne 0) { throw 'Plugin, theme or MU-plugin files are modified; review and commit them before packaging.' }

$head = (& git -C $repo rev-parse HEAD).Trim()
if ($LASTEXITCODE -ne 0 -or $head -notmatch '^[0-9a-f]{40}$') { throw 'Cannot identify the release commit.' }

$pluginFile = Join-Path $repo ($pluginRoot.Replace('/', '\') + '\auto-dealership-core.php')
$themeFile = Join-Path $repo ($themeRoot.Replace('/', '\') + '\style.css')
$pluginHeader = Get-Content -LiteralPath $pluginFile -Raw
$themeHeader = Get-Content -LiteralPath $themeFile -Raw
$pluginMatch = [regex]::Match($pluginHeader, '(?m)^\s*\*\s*Version:\s*([^\r\n]+)')
$themeMatch = [regex]::Match($themeHeader, '(?m)^Version:\s*([^\r\n]+)')
if (-not $pluginMatch.Success -or -not $themeMatch.Success) { throw 'A release version is missing.' }
$pluginVersion = $pluginMatch.Groups[1].Value.Trim()
$themeVersion = $themeMatch.Groups[1].Value.Trim()

$pluginFiles = @(& git -C $repo ls-files -- $pluginRoot) | Where-Object {
    $_ -notmatch '^wp-content/plugins/auto-dealership-core/tests/' -and
    $_ -notin @(
        'wp-content/plugins/auto-dealership-core/composer.json',
        'wp-content/plugins/auto-dealership-core/composer.lock'
    ) -and
    $_ -notmatch '^wp-content/plugins/auto-dealership-core/vendor/'
}
$themeFiles = @(& git -C $repo ls-files -- $themeRoot) | Where-Object {
    $_ -notmatch '^wp-content/themes/car-dealer/tests/' -and
    $_ -notmatch '^wp-content/themes/car-dealer/docs/' -and
    $_ -notin @(
        'wp-content/themes/car-dealer/build-css.js',
        'wp-content/themes/car-dealer/merge-css.ps1'
    )
}
$files = @($pluginFiles + $themeFiles + $muFiles)
if ($LASTEXITCODE -ne 0 -or $files.Count -eq 0) { throw 'Cannot list runtime files.' }

foreach ($path in $files) {
    if ($path -match '(^|/)tests?(/|$)' -or
        $path -match '(^|/)wp-config\.php$' -or
        $path -match '(^|/)\.git(/|$)' -or
        $path -match '(^|/)node_modules(/|$)' -or
        $path -match '(^|/)composer\.(json|lock)$' -or
        $path -match '^wp-content/plugins/auto-dealership-core/vendor/') {
        throw "Unsafe file selected for hosting bundle: $path"
    }
    $absolute = Join-Path $repo ($path.Replace('/', '\'))
    if (-not (Test-Path -LiteralPath $absolute -PathType Leaf)) { throw "Missing runtime file: $path" }
}

New-Item -ItemType Directory -Path $OutputDirectory -Force | Out-Null
$stage = Join-Path $repo ('.tmp/hosting-bundle-' + [guid]::NewGuid().ToString('N'))
$name = 'dealership-hosting-bundle-plugin-{0}-theme-{1}-{2}.zip' -f $pluginVersion, $themeVersion, $head.Substring(0, 12)
$archivePath = Join-Path $OutputDirectory $name
$manifestPath = Join-Path $OutputDirectory ($name + '.manifest.json')
if ((Test-Path -LiteralPath $archivePath) -or (Test-Path -LiteralPath $manifestPath)) {
    throw "The output package already exists: $name"
}

$manifestFiles = @()
try {
    New-Item -ItemType Directory -Path $stage -Force | Out-Null
    foreach ($path in $files) {
        $source = Join-Path $repo ($path.Replace('/', '\'))
        $destination = Join-Path $stage ($path.Replace('/', '\'))
        $destinationDirectory = Split-Path -Parent $destination
        New-Item -ItemType Directory -Path $destinationDirectory -Force | Out-Null
        Copy-Item -LiteralPath $source -Destination $destination
        $manifestFiles += [ordered]@{
            path = $path
            bytes = (Get-Item -LiteralPath $source).Length
            sha256 = (Get-FileHash -LiteralPath $source -Algorithm SHA256).Hash.ToLowerInvariant()
        }
    }

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    Add-Type -AssemblyName System.IO.Compression
    $archive = [System.IO.Compression.ZipFile]::Open(
        $archivePath,
        [System.IO.Compression.ZipArchiveMode]::Create
    )
    try {
        foreach ($path in $files) {
            $source = Join-Path $repo ($path.Replace('/', '\'))
            $entry = $archive.CreateEntry(
                $path.Replace('\', '/'),
                [System.IO.Compression.CompressionLevel]::Optimal
            )
            $sourceStream = [System.IO.File]::OpenRead($source)
            $entryStream = $entry.Open()
            try {
                $sourceStream.CopyTo($entryStream)
            } finally {
                $entryStream.Dispose()
                $sourceStream.Dispose()
            }
        }
    } finally {
        $archive.Dispose()
    }

    $archive = [System.IO.Compression.ZipFile]::OpenRead($archivePath)
    try {
        $entries = @($archive.Entries | Where-Object { -not $_.FullName.EndsWith('/') })
        if ($entries.Count -ne $files.Count) { throw 'Archive file count does not match the selected runtime files.' }
        foreach ($entry in $entries) {
            if ($entry.FullName -match '(^|/)tests?(/|$)' -or
                $entry.FullName -match '(^|/)wp-config\.php$' -or
                $entry.FullName -match '(^|/)composer\.(json|lock)$' -or
                $entry.FullName -match '^wp-content/plugins/auto-dealership-core/vendor/') {
                throw "Unsafe archive entry: $($entry.FullName)"
            }
        }
    } finally {
        $archive.Dispose()
    }

    $manifest = [ordered]@{
        package = $name
        package_sha256 = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant()
        source_commit = $head
        plugin_version = $pluginVersion
        theme_version = $themeVersion
        file_count = $manifestFiles.Count
        excluded = @('tests/', 'composer.json', 'composer.lock', 'vendor/', 'theme docs/', 'theme build tools')
        files = $manifestFiles
    }
    $manifest | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath $manifestPath -Encoding UTF8
    Write-Output "Package: $archivePath"
    Write-Output "Manifest: $manifestPath"
    Write-Output "Source commit: $head"
    Write-Output "Plugin: $pluginVersion; Theme: $themeVersion; files: $($manifestFiles.Count)"
    Write-Output "SHA-256: $($manifest.package_sha256)"
} finally {
    if (Test-Path -LiteralPath $stage) { Remove-Item -LiteralPath $stage -Recurse -Force }
}
