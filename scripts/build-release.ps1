param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^\d+\.\d+\.\d+([\-+][A-Za-z0-9\.\-]+)?$')]
    [string]$Version,

    [string]$PluginSlug = "kanguru-support",
    [string]$OutputDir = "dist"
)

$ErrorActionPreference = "Stop"

$repoRoot = Split-Path -Parent $PSScriptRoot
$releaseRoot = Join-Path $repoRoot ".release-tmp"
$stageRoot = Join-Path $releaseRoot $PluginSlug
$zipName = "$PluginSlug-$Version.zip"
$outputPath = Join-Path $repoRoot $OutputDir
$zipPath = Join-Path $outputPath $zipName
$stagedFiles = @()

function Should-ExcludeFile([string]$relativePath, [string]$fileName) {
    $normalized = $relativePath.Replace('\', '/')
    $name = $fileName.ToLowerInvariant()

    if ($normalized -match '(^|/)\.git(/|$)') { return $true }
    if ($normalized -match '(^|/)\.github(/|$)') { return $true }
    if ($normalized -match '(^|/)node_modules(/|$)') { return $true }
    if ($normalized -match '(^|/)backend/storage(/|$)') { return $true }
    if ($normalized -match '(^|/)backend/logs(/|$)') { return $true }
    if ($normalized -match '(^|/)backend/uploads(/|$)') { return $true }
    if ($normalized -match '(^|/)backend/database(/|$)') { return $true }
    if ($normalized -match '(^|/)\.release-tmp(/|$)') { return $true }
    if ($normalized -match '(^|/)dist(/|$)') { return $true }
    if ($name -eq ".env") { return $true }
    if ($name -eq "debug.log") { return $true }
    if ($name.EndsWith(".zip")) { return $true }

    return $false
}

if (Test-Path $releaseRoot) {
    Remove-Item -Recurse -Force $releaseRoot
}
New-Item -ItemType Directory -Path $stageRoot -Force | Out-Null

if (-not (Test-Path $outputPath)) {
    New-Item -ItemType Directory -Path $outputPath -Force | Out-Null
}

Get-ChildItem -Path $repoRoot -Recurse -File | ForEach-Object {
    $full = $_.FullName
    $relative = $full.Substring($repoRoot.Length).TrimStart('\', '/')

    # Safety: if script is ever run from a parent workspace where files are already
    # under "<slug>/...", strip that prefix to avoid double nesting in the ZIP.
    $slugPrefix = "$PluginSlug/"
    $relativeNormalized = $relative.Replace('\', '/')
    if ($relativeNormalized.StartsWith($slugPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
        $relative = $relativeNormalized.Substring($slugPrefix.Length)
    }

    if (Should-ExcludeFile $relative $_.Name) {
        return
    }

    $target = Join-Path $stageRoot $relative
    $targetDir = Split-Path -Parent $target
    if (-not (Test-Path $targetDir)) {
        New-Item -ItemType Directory -Path $targetDir -Force | Out-Null
    }
    Copy-Item -LiteralPath $full -Destination $target -Force
    $stagedFiles += [pscustomobject]@{
        SourcePath = $target
        EntryName = ($relative.Replace('\', '/'))
    }
}

if (Test-Path $zipPath) {
    Remove-Item -Force $zipPath
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$zipFile = [System.IO.File]::Open($zipPath, [System.IO.FileMode]::CreateNew)
try {
    $archive = New-Object System.IO.Compression.ZipArchive($zipFile, [System.IO.Compression.ZipArchiveMode]::Create, $false)
    try {
        foreach ($file in $stagedFiles) {
            $entryPath = "$PluginSlug/$($file.EntryName)"
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $archive,
                $file.SourcePath,
                $entryPath,
                [System.IO.Compression.CompressionLevel]::Optimal
            ) | Out-Null
        }
    }
    finally {
        $archive.Dispose()
    }
}
finally {
    $zipFile.Dispose()
}

Remove-Item -Recurse -Force $releaseRoot

Write-Host "Release ZIP created: $zipPath"
