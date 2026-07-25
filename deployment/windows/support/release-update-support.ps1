function Test-KaevCmsVersion {
    param([Parameter(Mandatory = $true)][string]$Version)

    return $Version -match '^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$'
}

function Get-KaevCmsPendingUpdateMarkerPath {
    param([Parameter(Mandatory = $true)][string]$ProjectRoot)

    return Join-Path $ProjectRoot 'storage\app\kaevcms\pending-update.json'
}

function Write-KaevCmsPendingUpdateMarker {
    param(
        [Parameter(Mandatory = $true)][string]$ProjectRoot,
        [Parameter(Mandatory = $true)][string]$FromVersion,
        [Parameter(Mandatory = $true)][string]$ToVersion
    )

    if (-not (Test-KaevCmsVersion -Version $FromVersion) -or -not (Test-KaevCmsVersion -Version $ToVersion)) {
        throw 'Pending update marker contains an invalid release number.'
    }

    $markerPath = Get-KaevCmsPendingUpdateMarkerPath -ProjectRoot $ProjectRoot
    New-Item -Path (Split-Path -Parent $markerPath) -ItemType Directory -Force | Out-Null

    [ordered]@{
        from_version = $FromVersion
        to_version = $ToVersion
        created_at = (Get-Date).ToUniversalTime().ToString('o')
    } | ConvertTo-Json -Compress | Set-Content -LiteralPath $markerPath -Encoding UTF8
}

function Remove-KaevCmsPendingUpdateMarker {
    param([Parameter(Mandatory = $true)][string]$ProjectRoot)

    $markerPath = Get-KaevCmsPendingUpdateMarkerPath -ProjectRoot $ProjectRoot
    if (Test-Path -LiteralPath $markerPath -PathType Leaf) {
        Remove-Item -LiteralPath $markerPath -Force -ErrorAction Stop
    }
}

function Convert-KaevCmsSupersededPendingUpdateMarker {
    param(
        [Parameter(Mandatory = $true)][string]$ProjectRoot,
        [Parameter(Mandatory = $true)][string]$ExpectedFromVersion,
        [Parameter(Mandatory = $true)][string]$ExpectedToVersion,
        [Parameter(Mandatory = $true)][string[]]$SupersededToVersions
    )

    $markerPath = Get-KaevCmsPendingUpdateMarkerPath -ProjectRoot $ProjectRoot
    if (-not (Test-Path -LiteralPath $markerPath -PathType Leaf)) {
        return $false
    }

    try {
        $pending = Get-Content -LiteralPath $markerPath -Raw | ConvertFrom-Json -ErrorAction Stop
    } catch {
        throw "Pending update marker is invalid: $markerPath"
    }

    $fromVersion = [string]$pending.from_version
    $toVersion = [string]$pending.to_version
    if (-not (Test-KaevCmsVersion -Version $fromVersion) -or -not (Test-KaevCmsVersion -Version $toVersion)) {
        throw 'Pending update marker contains an invalid release number.'
    }

    if ($fromVersion -ne $ExpectedFromVersion -or $SupersededToVersions -notcontains $toVersion) {
        return $false
    }

    Write-KaevCmsPendingUpdateMarker `
        -ProjectRoot $ProjectRoot `
        -FromVersion $ExpectedFromVersion `
        -ToVersion $ExpectedToVersion

    return $true
}

function Get-KaevCmsInstalledVersion {
    param(
        [Parameter(Mandatory = $true)][string]$ProjectRoot,
        [Parameter(Mandatory = $true)][string]$ExpectedFromVersion,
        [Parameter(Mandatory = $true)][string]$ExpectedToVersion,
        [Parameter(Mandatory = $true)][string]$LegacyApplyScriptName,
        [Parameter(Mandatory = $true)][string]$LegacyApplySha256
    )

    $markerPath = Join-Path $ProjectRoot 'storage\app\kaevcms\installed-version.json'
    if (Test-Path -LiteralPath $markerPath -PathType Leaf) {
        try {
            $marker = Get-Content -LiteralPath $markerPath -Raw | ConvertFrom-Json -ErrorAction Stop
        } catch {
            throw "Installed version marker is invalid: $markerPath"
        }

        $version = [string]$marker.version
        if (-not (Test-KaevCmsVersion -Version $version)) {
            throw "Installed version marker contains an invalid version: $version"
        }

        return [pscustomobject]@{
            Version = $version
            Source = 'marker'
        }
    }

    $pendingPath = Get-KaevCmsPendingUpdateMarkerPath -ProjectRoot $ProjectRoot
    if (Test-Path -LiteralPath $pendingPath -PathType Leaf) {
        try {
            $pending = Get-Content -LiteralPath $pendingPath -Raw | ConvertFrom-Json -ErrorAction Stop
        } catch {
            throw "Pending update marker is invalid: $pendingPath"
        }

        $fromVersion = [string]$pending.from_version
        $toVersion = [string]$pending.to_version
        if (-not (Test-KaevCmsVersion -Version $fromVersion) -or -not (Test-KaevCmsVersion -Version $toVersion)) {
            throw 'Pending update marker contains an invalid release number.'
        }
        if ($fromVersion -ne $ExpectedFromVersion -or $toVersion -ne $ExpectedToVersion) {
            throw "Pending update marker belongs to $fromVersion -> $toVersion, not $ExpectedFromVersion -> $ExpectedToVersion."
        }

        return [pscustomobject]@{
            Version = $fromVersion
            Source = 'pending-update'
        }
    }

    $legacyApplyPath = Join-Path $ProjectRoot $LegacyApplyScriptName
    if (-not (Test-Path -LiteralPath $legacyApplyPath -PathType Leaf)) {
        throw "Installed version cannot be verified. Expected marker, pending update marker or $LegacyApplyScriptName from KaevCMS $ExpectedFromVersion."
    }

    $actualHash = (Get-FileHash -LiteralPath $legacyApplyPath -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actualHash -ne $LegacyApplySha256.ToLowerInvariant()) {
        throw "Installed version cannot be verified because $LegacyApplyScriptName does not match the official KaevCMS $ExpectedFromVersion release."
    }

    return [pscustomobject]@{
        Version = $ExpectedFromVersion
        Source = 'legacy-apply-fingerprint'
    }
}

function Move-KaevCmsArtifactsToBackup {
    param(
        [Parameter(Mandatory = $true)][string]$ProjectRoot,
        [Parameter(Mandatory = $true)][string]$TargetVersion,
        [Parameter(Mandatory = $true)][string[]]$RelativePaths
    )

    if (-not (Test-KaevCmsVersion -Version $TargetVersion)) {
        throw "Invalid backup target version: $TargetVersion"
    }

    $sessionName = '{0}-{1}' -f (Get-Date -Format 'yyyyMMdd-HHmmss'), [guid]::NewGuid().ToString('N')
    $backupRoot = Join-Path $ProjectRoot (Join-Path 'storage\app\kaevcms\update-backups' (Join-Path $TargetVersion $sessionName))
    $moved = @()

    foreach ($relativePath in ($RelativePaths | Select-Object -Unique)) {
        if ([string]::IsNullOrWhiteSpace($relativePath)) {
            continue
        }

        $sourcePath = Join-Path $ProjectRoot $relativePath
        if (-not (Test-Path -LiteralPath $sourcePath)) {
            continue
        }

        $destinationPath = Join-Path $backupRoot $relativePath
        New-Item -Path (Split-Path -Parent $destinationPath) -ItemType Directory -Force | Out-Null
        Move-Item -LiteralPath $sourcePath -Destination $destinationPath -Force -ErrorAction Stop
        $moved += $relativePath
    }

    return [pscustomobject]@{
        Root = $backupRoot
        Paths = $moved
    }
}

function Remove-KaevCmsUpdateBackups {
    param(
        [Parameter(Mandatory = $true)][string]$ProjectRoot,
        [Parameter(Mandatory = $true)][string]$TargetVersion
    )

    $backupRoot = Join-Path $ProjectRoot (Join-Path 'storage\app\kaevcms\update-backups' $TargetVersion)
    if (Test-Path -LiteralPath $backupRoot) {
        Remove-Item -LiteralPath $backupRoot -Recurse -Force -ErrorAction Stop
    }
}

function Initialize-KaevCmsRuntimeDirectories {
    param([Parameter(Mandatory = $true)][string]$ProjectRoot)

    foreach ($relativePath in @(
        'bootstrap\cache',
        'storage\app\private',
        'storage\app\public',
        'storage\framework\cache\data',
        'storage\framework\sessions',
        'storage\framework\views',
        'storage\logs'
    )) {
        $directoryPath = Join-Path $ProjectRoot $relativePath
        if (-not (Test-Path -LiteralPath $directoryPath -PathType Container)) {
            New-Item -Path $directoryPath -ItemType Directory -Force | Out-Null
        }
    }
}

function Clear-KaevCmsBootstrapCache {
    param([Parameter(Mandatory = $true)][string]$ProjectRoot)

    $cachePath = Join-Path $ProjectRoot 'bootstrap\cache'
    if (-not (Test-Path -LiteralPath $cachePath -PathType Container)) {
        New-Item -Path $cachePath -ItemType Directory -Force | Out-Null
        return
    }

    Get-ChildItem -LiteralPath $cachePath -File -ErrorAction Stop |
        Where-Object { $_.Extension -eq '.php' } |
        Remove-Item -Force -ErrorAction Stop
}
