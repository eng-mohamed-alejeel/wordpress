param(
    [string]$OutputDirectory = '',
    [switch]$ReviewWorkingTree
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repo = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    $OutputDirectory = Join-Path $repo 'release-packages/dashboard'
} else {
    $OutputDirectory = [System.IO.Path]::GetFullPath($OutputDirectory)
}

$pluginRoot = 'wp-content/plugins/auto-dealership-core'
$themeRoot = 'wp-content/themes/car-dealer'
$releaseRoots = @($pluginRoot, $themeRoot)
$status = @(& git -C $repo status --porcelain --untracked-files=all -- $releaseRoots)
if ($LASTEXITCODE -ne 0) { throw 'Cannot inspect the plugin and theme working tree.' }
if ($status.Count -ne 0 -and -not $ReviewWorkingTree) { throw 'Plugin or theme files are modified; review and commit them before packaging, or use -ReviewWorkingTree for a reviewed candidate.' }

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
if ($LASTEXITCODE -ne 0 -or $pluginFiles.Count -eq 0 -or $themeFiles.Count -eq 0) {
    throw 'Cannot list dashboard package files.'
}

New-Item -ItemType Directory -Path $OutputDirectory -Force | Out-Null
Add-Type -AssemblyName System.IO.Compression.FileSystem
Add-Type -AssemblyName System.IO.Compression

function New-DashboardPackage {
    param(
        [string]$PackageName,
        [string]$PackageRoot,
        [string]$SourceRoot,
        [string[]]$SourceFiles,
        [string]$Version
    )

    $archivePath = Join-Path $OutputDirectory ($PackageName + '.zip')
    $manifestPath = Join-Path $OutputDirectory ($PackageName + '.manifest.json')
    if ((Test-Path -LiteralPath $archivePath) -or (Test-Path -LiteralPath $manifestPath)) {
        throw "The output package already exists: $PackageName"
    }

    $archive = [System.IO.Compression.ZipFile]::Open(
        $archivePath,
        [System.IO.Compression.ZipArchiveMode]::Create
    )
    $manifestFiles = @()
    try {
        $prefix = $SourceRoot + '/'
        foreach ($path in $SourceFiles) {
            if ($path -notlike ($prefix + '*')) { throw "File is outside package root: $path" }
            $relative = $path.Substring($prefix.Length)
            if ($relative -match '(^|/)tests?(/|$)|(^|/)composer\.(json|lock)$|(^|/)vendor(/|$)|(^|/)wp-config\.php$') {
                throw "Unsafe dashboard package file: $path"
            }
            $source = Join-Path $repo ($path.Replace('/', '\'))
            if (-not (Test-Path -LiteralPath $source -PathType Leaf)) { throw "Missing package file: $path" }
            $entry = $archive.CreateEntry(
                ($PackageRoot + '/' + $relative.Replace('\', '/')),
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
            $manifestFiles += [ordered]@{
                path = $PackageRoot + '/' + $relative.Replace('\', '/')
                bytes = (Get-Item -LiteralPath $source).Length
                sha256 = (Get-FileHash -LiteralPath $source -Algorithm SHA256).Hash.ToLowerInvariant()
            }
        }
    } finally {
        $archive.Dispose()
    }

    $archive = [System.IO.Compression.ZipFile]::OpenRead($archivePath)
    try {
        $entries = @($archive.Entries | Where-Object { -not $_.FullName.EndsWith('/') })
        if ($entries.Count -ne $SourceFiles.Count) { throw "Archive file count mismatch for $PackageName." }
        foreach ($entry in $entries) {
            if ($entry.FullName.Contains('\') -or
                $entry.FullName -match '(^|/)tests?(/|$)|(^|/)composer\.(json|lock)$|(^|/)vendor(/|$)|(^|/)wp-config\.php$') {
                throw "Unsafe archive entry: $($entry.FullName)"
            }
        }
    } finally {
        $archive.Dispose()
    }

    $manifest = [ordered]@{
        package = $PackageName + '.zip'
        install_mode = 'WordPress dashboard'
        source_commit = $head
        working_tree_review = [bool]$ReviewWorkingTree
        version = $Version
        file_count = $manifestFiles.Count
        package_sha256 = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant()
        files = $manifestFiles
    }
    $manifest | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath $manifestPath -Encoding UTF8
    Write-Output "Package: $archivePath"
    Write-Output "Manifest: $manifestPath"
    Write-Output "Version: $Version; files: $($manifestFiles.Count); SHA-256: $($manifest.package_sha256)"
}

New-DashboardPackage `
    -PackageName ('auto-dealership-core-' + $pluginVersion) `
    -PackageRoot 'auto-dealership-core' `
    -SourceRoot $pluginRoot `
    -SourceFiles $pluginFiles `
    -Version $pluginVersion

New-DashboardPackage `
    -PackageName ('car-dealer-' + $themeVersion) `
    -PackageRoot 'car-dealer' `
    -SourceRoot $themeRoot `
    -SourceFiles $themeFiles `
    -Version $themeVersion
