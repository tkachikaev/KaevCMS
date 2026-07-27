function Test-KaevCmsVersion {
    param([Parameter(Mandatory = $true)][string]$Version)

    return $Version -match '^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$'
}



function Test-KaevCmsRelativePath {
    param([Parameter(Mandatory = $true)][string]$Path)

    if ([string]::IsNullOrWhiteSpace($Path) -or [System.IO.Path]::IsPathRooted($Path)) {
        return $false
    }

    $segments = $Path.Replace('\', '/').Split('/')

    return $segments -notcontains '..' -and $segments -notcontains ''
}

function ConvertTo-KaevCmsPlatformPath {
    param([Parameter(Mandatory = $true)][string]$Path)

    return $Path.Replace('/', [System.IO.Path]::DirectorySeparatorChar)
}

function Read-KaevCmsJsonFile {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$Label
    )

    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "$Label is missing: $Path"
    }

    try {
        return Get-Content -LiteralPath $Path -Raw | ConvertFrom-Json -ErrorAction Stop
    } catch {
        throw "$Label is invalid: $Path"
    }
}

function Get-KaevCmsReleaseContract {
    param([Parameter(Mandatory = $true)][string]$ProjectRoot)

    $contractPath = Join-Path $ProjectRoot 'release.json'
    $contract = Read-KaevCmsJsonFile -Path $contractPath -Label 'Release contract'

    if ([int]$contract.schema -ne 1) {
        throw 'Release contract schema is unsupported.'
    }

    $parsedReleaseDate = [datetime]::MinValue
    if (-not [datetime]::TryParseExact(
        [string]$contract.released_at,
        'yyyy-MM-dd',
        [System.Globalization.CultureInfo]::InvariantCulture,
        [System.Globalization.DateTimeStyles]::None,
        [ref]$parsedReleaseDate
    )) {
        throw 'Release contract contains an invalid released_at date.'
    }

    foreach ($property in @('version', 'previous_version', 'recovery_floor_version', 'cumulative_base_version')) {
        $value = [string]$contract.$property
        if (-not (Test-KaevCmsVersion -Version $value)) {
            throw "Release contract contains an invalid $property value: $value"
        }
    }

    if ([version]$contract.previous_version -ge [version]$contract.version) {
        throw 'Release contract previous_version must be lower than version.'
    }
    if ([version]$contract.recovery_floor_version -gt [version]$contract.previous_version) {
        throw 'Release contract recovery_floor_version must not exceed previous_version.'
    }
    if ([version]$contract.cumulative_base_version -gt [version]$contract.recovery_floor_version) {
        throw 'Release contract cumulative_base_version must not exceed recovery_floor_version.'
    }

    foreach ($property in @('apply_script', 'update_script', 'previous_apply_script')) {
        $value = [string]$contract.$property
        if (-not (Test-KaevCmsRelativePath -Path $value)) {
            throw "Release contract contains an invalid $property path: $value"
        }
    }

    if ([string]$contract.apply_script -ne "deployment/windows/apply-$($contract.version).ps1") {
        throw 'Release contract apply_script does not match version.'
    }
    if ([string]$contract.update_script -ne 'deployment/windows/update.ps1') {
        throw 'Release contract update_script must reference deployment/windows/update.ps1.'
    }
    if ([string]$contract.previous_apply_script -ne "deployment/windows/apply-$($contract.previous_version).ps1") {
        throw 'Release contract previous_apply_script does not match previous_version.'
    }

    foreach ($hash in @(
        [string]$contract.previous_apply_sha256,
        [string]$contract.composer_lock.previous_sha256,
        [string]$contract.composer_lock.current_sha256
    )) {
        if ($hash -notmatch '^[a-f0-9]{64}$') {
            throw 'Release contract contains an invalid SHA256 fingerprint.'
        }
    }

    $versionPath = Join-Path $ProjectRoot 'VERSION'
    if (-not (Test-Path -LiteralPath $versionPath -PathType Leaf)) {
        throw 'VERSION is missing.'
    }
    $versionFile = (Get-Content -LiteralPath $versionPath -Raw).Trim()
    if ($versionFile -ne [string]$contract.version) {
        throw "VERSION contains $versionFile, but release.json contains $($contract.version)."
    }

    return $contract
}

function Get-KaevCmsRequiredReleaseFiles {
    param([Parameter(Mandatory = $true)][string]$ProjectRoot)

    $manifest = Read-KaevCmsJsonFile `
        -Path (Join-Path $ProjectRoot 'deployment/release-files.json') `
        -Label 'Release file manifest'
    if ([int]$manifest.schema -ne 1) {
        throw 'Release file manifest schema is unsupported.'
    }

    $files = @($manifest.required_files | ForEach-Object { [string]$_ })
    if ($files.Count -eq 0) {
        throw 'Release file manifest is empty.'
    }

    foreach ($file in $files) {
        if (-not (Test-KaevCmsRelativePath -Path $file)) {
            throw "Release file manifest contains an invalid path: $file"
        }
    }

    if (($files | Select-Object -Unique).Count -ne $files.Count) {
        throw 'Release file manifest contains duplicate paths.'
    }

    return @($files)
}

function Assert-KaevCmsRequiredReleaseFiles {
    param(
        [Parameter(Mandatory = $true)][string]$ProjectRoot,
        [string]$Remediation = 'Re-extract the complete KaevCMS release or patch.'
    )

    foreach ($requiredFile in (Get-KaevCmsRequiredReleaseFiles -ProjectRoot $ProjectRoot)) {
        $requiredPath = Join-Path $ProjectRoot (ConvertTo-KaevCmsPlatformPath -Path $requiredFile)
        if (-not (Test-Path -LiteralPath $requiredPath -PathType Leaf)) {
            throw "Release file is missing: $requiredFile. $Remediation"
        }
    }
}

function Get-KaevCmsUpdateContract {
    param([Parameter(Mandatory = $true)][string]$ProjectRoot)

    $contract = Read-KaevCmsJsonFile `
        -Path (Join-Path $ProjectRoot 'deployment/windows/update-contract.json') `
        -Label 'Windows update contract'
    if ([int]$contract.schema -ne 1) {
        throw 'Windows update contract schema is unsupported.'
    }

    $runtimeDirectories = @($contract.runtime_directories | ForEach-Object { [string]$_ })
    $protectedEnvironmentFiles = @($contract.protected_environment_files | ForEach-Object { [string]$_ })
    $protectedEnvironmentKeys = @($contract.protected_environment_keys | ForEach-Object { [string]$_ })
    $stageOrder = @($contract.stage_order | ForEach-Object { [string]$_ })
    if ($runtimeDirectories.Count -eq 0 -or $protectedEnvironmentFiles.Count -eq 0 -or $protectedEnvironmentKeys.Count -eq 0 -or $stageOrder.Count -eq 0) {
        throw 'Windows update contract is incomplete.'
    }

    foreach ($relativePath in @($runtimeDirectories + $protectedEnvironmentFiles)) {
        if (-not (Test-KaevCmsRelativePath -Path $relativePath)) {
            throw "Windows update contract contains an invalid protected or runtime path: $relativePath"
        }
    }

    foreach ($environmentKey in $protectedEnvironmentKeys) {
        if ($environmentKey -notmatch '^[A-Z][A-Z0-9_]*$') {
            throw "Windows update contract contains an invalid environment key: $environmentKey"
        }
    }

    if ((@($runtimeDirectories | Select-Object -Unique)).Count -ne $runtimeDirectories.Count) {
        throw 'Windows update contract contains duplicate runtime directories.'
    }

    if ((@($protectedEnvironmentFiles | Select-Object -Unique)).Count -ne $protectedEnvironmentFiles.Count) {
        throw 'Windows update contract contains duplicate protected environment files.'
    }

    if ((@($protectedEnvironmentKeys | Select-Object -Unique)).Count -ne $protectedEnvironmentKeys.Count) {
        throw 'Windows update contract contains duplicate protected environment keys.'
    }

    if ((@($stageOrder | Select-Object -Unique)).Count -ne $stageOrder.Count) {
        throw 'Windows update contract contains duplicate update stages.'
    }

    return $contract
}

function Get-KaevCmsObsoleteReleaseArtifacts {
    param(
        [Parameter(Mandatory = $true)][string]$ProjectRoot,
        [Parameter(Mandatory = $true)][string]$CurrentVersion
    )

    if (-not (Test-KaevCmsVersion -Version $CurrentVersion)) {
        throw "Invalid release version: $CurrentVersion"
    }

    $history = Read-KaevCmsJsonFile `
        -Path (Join-Path $ProjectRoot 'deployment/updates/deletions.json') `
        -Label 'Update deletion history'
    $paths = @()
    foreach ($property in $history.PSObject.Properties) {
        if ($property.Name -notmatch '^\d+\.\d+\.\d+$' -or [version]$property.Name -gt [version]$CurrentVersion) {
            continue
        }

        foreach ($rawPath in @($property.Value)) {
            $relativePath = ([string]$rawPath).Replace('\', '/')
            if ($relativePath.StartsWith('core/')) {
                $relativePath = $relativePath.Substring(5)
            }
            if (-not (Test-KaevCmsRelativePath -Path $relativePath)) {
                throw "Update deletion history contains an invalid path: $rawPath"
            }
            $paths += ConvertTo-KaevCmsPlatformPath -Path $relativePath
        }
    }

    $currentApplyScript = "apply-$CurrentVersion.ps1"
    $windowsPath = Join-Path $ProjectRoot 'deployment\windows'
    if (Test-Path -LiteralPath $windowsPath -PathType Container) {
        foreach ($obsoleteApplyScript in Get-ChildItem -LiteralPath $windowsPath -Filter 'apply-*.ps1' -File -ErrorAction Stop) {
            if ($obsoleteApplyScript.Name -ne $currentApplyScript) {
                $paths += 'deployment\windows\' + $obsoleteApplyScript.Name
            }
        }
    }

    $unitTestPath = Join-Path $ProjectRoot 'tests\Unit'
    if (Test-Path -LiteralPath $unitTestPath -PathType Container) {
        foreach ($obsoleteItemImporterTest in Get-ChildItem -LiteralPath $unitTestPath -Filter '*ItemImporterTest.php' -File -ErrorAction Stop) {
            $paths += 'tests\Unit\' + $obsoleteItemImporterTest.Name
        }
    }

    return @($paths | Select-Object -Unique)
}

function Get-KaevCmsRecoveryLineage {
    param(
        [Parameter(Mandatory = $true)][string]$ProjectRoot,
        [Parameter(Mandatory = $true)][string]$RecoveryFloorVersion,
        [Parameter(Mandatory = $true)][string]$ExpectedFromVersion,
        [Parameter(Mandatory = $true)][string]$ExpectedToVersion
    )

    foreach ($version in @($RecoveryFloorVersion, $ExpectedFromVersion, $ExpectedToVersion)) {
        if ($version -notmatch '^\d+\.\d+\.\d+$') {
            throw "Recovery lineage requires a stable semantic version: $version"
        }
    }

    $floor = [version]$RecoveryFloorVersion
    $expectedFrom = [version]$ExpectedFromVersion
    $expectedTo = [version]$ExpectedToVersion
    if ($floor -gt $expectedFrom -or $expectedFrom -ge $expectedTo) {
        throw 'Recovery lineage version boundaries are invalid.'
    }

    $historyPath = Join-Path $ProjectRoot 'deployment\updates\deletions.json'
    if (-not (Test-Path -LiteralPath $historyPath -PathType Leaf)) {
        throw "Update deletion history is missing: $historyPath"
    }

    try {
        $history = Get-Content -LiteralPath $historyPath -Raw | ConvertFrom-Json -ErrorAction Stop
    } catch {
        throw "Update deletion history is invalid: $historyPath"
    }

    $historyVersions = @(
        $history.PSObject.Properties.Name |
            Where-Object { $_ -match '^\d+\.\d+\.\d+$' } |
            Sort-Object { [version]$_ }
    )

    if ($historyVersions -notcontains $ExpectedFromVersion) {
        throw "Update deletion history does not contain the expected source release: $ExpectedFromVersion"
    }

    $supersededPendingTargets = @(
        $historyVersions |
            Where-Object {
                $candidate = [version]$_
                $candidate -gt $floor -and $candidate -le $expectedFrom
            }
    )
    $recoverableFromVersions = @(
        (@($RecoveryFloorVersion) + @($supersededPendingTargets | Where-Object { $_ -ne $ExpectedFromVersion })) |
            Select-Object -Unique
    )

    return [pscustomobject]@{
        RecoverableFromVersions = $recoverableFromVersions
        SupersededPendingTargets = $supersededPendingTargets
    }
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

    $contract = Get-KaevCmsUpdateContract -ProjectRoot $ProjectRoot
    foreach ($relativePath in @($contract.runtime_directories)) {
        $directoryPath = Join-Path $ProjectRoot (ConvertTo-KaevCmsPlatformPath -Path ([string]$relativePath))
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
