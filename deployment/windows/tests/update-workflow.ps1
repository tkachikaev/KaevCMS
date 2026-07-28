$ErrorActionPreference = 'Stop'
$ProjectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..\..'))
Set-Location -LiteralPath $ProjectRoot

. "$PSScriptRoot\..\support\release-update-support.ps1"

function Assert-True {
    param(
        [Parameter(Mandatory = $true)][bool]$Condition,
        [Parameter(Mandatory = $true)][string]$Message
    )

    if (-not $Condition) {
        throw $Message
    }
}

function Assert-Equal {
    param(
        [AllowNull()]$Actual,
        [AllowNull()]$Expected,
        [Parameter(Mandatory = $true)][string]$Message
    )

    if ($Actual -ne $Expected) {
        throw "$Message Expected: $Expected; actual: $Actual"
    }
}

function Assert-Sequence {
    param(
        [Parameter(Mandatory = $true)][object[]]$Actual,
        [Parameter(Mandatory = $true)][object[]]$Expected,
        [Parameter(Mandatory = $true)][string]$Message
    )

    Assert-Equal -Actual ($Actual -join '|') -Expected ($Expected -join '|') -Message $Message
}

$release = Get-KaevCmsReleaseContract -ProjectRoot $ProjectRoot
$updateContract = Get-KaevCmsUpdateContract -ProjectRoot $ProjectRoot
$requiredFiles = Get-KaevCmsRequiredReleaseFiles -ProjectRoot $ProjectRoot

Assert-Equal -Actual ([string]$release.version) -Expected ((Get-Content -LiteralPath (Join-Path $ProjectRoot 'VERSION') -Raw).Trim()) -Message 'VERSION and release.json differ.'
Assert-Equal -Actual ([string]$release.apply_script) -Expected ("deployment/windows/apply-$($release.version).ps1") -Message 'Current apply script path is not derived from the release version.'
Assert-Equal -Actual ([string]$release.previous_apply_script) -Expected ("deployment/windows/apply-$($release.previous_version).ps1") -Message 'Previous apply script path is not derived from the previous release version.'
Assert-True ($requiredFiles -contains 'release.json') 'Release file manifest does not require release.json.'
Assert-True ($requiredFiles -contains 'deployment/windows/update-contract.json') 'Release file manifest does not require the Windows update contract.'
Assert-True ($requiredFiles -contains [string]$release.apply_script) 'Release file manifest does not require the current apply script.'

Assert-KaevCmsRequiredReleaseFiles -ProjectRoot $ProjectRoot

Assert-Sequence -Actual @($updateContract.stage_order) -Expected @(
    'preflight',
    'backup_obsolete',
    'maintenance',
    'dependencies',
    'cache_clear',
    'migrations',
    'queue_restart',
    'monitoring',
    'tests',
    'record_release',
    'cleanup'
) -Message 'Windows update stage order changed unexpectedly.'
Assert-True (@($updateContract.protected_environment_files) -contains '.env') 'The Windows update contract does not protect .env.'
foreach ($protectedKey in @('APP_KEY', 'DB_PASSWORD', 'MAIL_PASSWORD')) {
    Assert-True (@($updateContract.protected_environment_keys) -contains $protectedKey) "Protected environment key is missing: $protectedKey"
}

$recoveryLineage = Get-KaevCmsRecoveryLineage `
    -ProjectRoot $ProjectRoot `
    -RecoveryFloorVersion ([string]$release.recovery_floor_version) `
    -ExpectedFromVersion ([string]$release.previous_version) `
    -ExpectedToVersion ([string]$release.version)
if ([string]$release.recovery_floor_version -eq [string]$release.previous_version) {
    Assert-Equal -Actual @($recoveryLineage.RecoverableFromVersions).Count -Expected 0 -Message 'A direct previous baseline was duplicated in recoverable versions.'
    Assert-Equal -Actual @($recoveryLineage.SupersededPendingTargets).Count -Expected 0 -Message 'A direct previous baseline was incorrectly treated as a superseded pending target.'
} else {
    Assert-Equal -Actual $recoveryLineage.RecoverableFromVersions[0] -Expected ([string]$release.recovery_floor_version) -Message 'Recovery lineage does not start at the configured floor.'
    Assert-True ($recoveryLineage.RecoverableFromVersions -contains [string]$release.recovery_floor_version) 'Recovery floor is not recoverable.'
    Assert-True ($recoveryLineage.SupersededPendingTargets -contains [string]$release.previous_version) 'The previous release is not a superseded pending target.'
}
Assert-True ($recoveryLineage.RecoverableFromVersions -notcontains [string]$release.previous_version) 'The direct previous version was duplicated in recoverable versions.'

$baselineRecoveryLineage = Get-KaevCmsRecoveryLineage `
    -ProjectRoot $ProjectRoot `
    -RecoveryFloorVersion ([string]$release.previous_version) `
    -ExpectedFromVersion ([string]$release.previous_version) `
    -ExpectedToVersion ([string]$release.version)
Assert-Equal -Actual @($baselineRecoveryLineage.RecoverableFromVersions).Count -Expected 0 -Message 'A newly established baseline duplicated the direct previous version in recoverable versions.'
Assert-Equal -Actual @($baselineRecoveryLineage.SupersededPendingTargets).Count -Expected 0 -Message 'A newly established baseline created a false superseded pending target.'

$obsoleteArtifacts = Get-KaevCmsObsoleteReleaseArtifacts -ProjectRoot $ProjectRoot -CurrentVersion ([string]$release.version)
Assert-True ($obsoleteArtifacts -contains (ConvertTo-KaevCmsPlatformPath -Path ([string]$release.previous_apply_script))) 'The previous apply script is not scheduled for cleanup.'
Assert-True ($obsoleteArtifacts -contains (ConvertTo-KaevCmsPlatformPath -Path 'public/assets/admin/css/app.css')) 'The obsolete monolithic administration stylesheet is not scheduled for cleanup.'
Assert-True ($obsoleteArtifacts -notcontains (ConvertTo-KaevCmsPlatformPath -Path 'public/assets/account')) 'The shared account runtime directory is incorrectly scheduled for cleanup.'

$tempRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('kaevcms-update-test-' + [guid]::NewGuid().ToString('N'))
try {
    New-Item -Path $tempRoot -ItemType Directory -Force | Out-Null
    New-Item -Path (Join-Path $tempRoot 'deployment\updates') -ItemType Directory -Force | Out-Null
    Copy-Item -LiteralPath (Join-Path $ProjectRoot 'deployment\updates\deletions.json') -Destination (Join-Path $tempRoot 'deployment\updates\deletions.json')
    New-Item -Path (Join-Path $tempRoot 'deployment\windows') -ItemType Directory -Force | Out-Null
    Copy-Item -LiteralPath (Join-Path $ProjectRoot 'deployment\windows\update-contract.json') -Destination (Join-Path $tempRoot 'deployment\windows\update-contract.json')

    Initialize-KaevCmsRuntimeDirectories -ProjectRoot $tempRoot
    foreach ($runtimeDirectory in @($updateContract.runtime_directories)) {
        $runtimePath = Join-Path $tempRoot (ConvertTo-KaevCmsPlatformPath -Path ([string]$runtimeDirectory))
        Assert-True (Test-Path -LiteralPath $runtimePath -PathType Container) "Runtime directory was not created: $runtimeDirectory"
    }

    $sourceVersion = [string]$release.recovery_floor_version
    $supersededTarget = [string]$recoveryLineage.SupersededPendingTargets[0]
    Write-KaevCmsPendingUpdateMarker -ProjectRoot $tempRoot -FromVersion $sourceVersion -ToVersion $supersededTarget
    $converted = Convert-KaevCmsSupersededPendingUpdateMarker `
        -ProjectRoot $tempRoot `
        -ExpectedFromVersion $sourceVersion `
        -ExpectedToVersion ([string]$release.version) `
        -SupersededToVersions @($recoveryLineage.SupersededPendingTargets)
    Assert-True $converted 'A superseded pending update marker was not adopted.'

    $pendingResult = Get-KaevCmsInstalledVersion `
        -ProjectRoot $tempRoot `
        -ExpectedFromVersion $sourceVersion `
        -ExpectedToVersion ([string]$release.version) `
        -LegacyApplyScriptName (ConvertTo-KaevCmsPlatformPath -Path ([string]$release.previous_apply_script)) `
        -LegacyApplySha256 ([string]$release.previous_apply_sha256)
    Assert-Equal -Actual $pendingResult.Version -Expected $sourceVersion -Message 'Pending recovery did not preserve the committed source version.'
    Assert-Equal -Actual $pendingResult.Source -Expected 'pending-update' -Message 'Pending recovery source was not reported.'
    Remove-KaevCmsPendingUpdateMarker -ProjectRoot $tempRoot

    $markerDirectory = Join-Path $tempRoot 'storage\app\kaevcms'
    New-Item -Path $markerDirectory -ItemType Directory -Force | Out-Null
    $markerPath = Join-Path $markerDirectory 'installed-version.json'
    [ordered]@{ version = [string]$release.version } | ConvertTo-Json -Compress | Set-Content -LiteralPath $markerPath -Encoding UTF8
    $markerResult = Get-KaevCmsInstalledVersion `
        -ProjectRoot $tempRoot `
        -ExpectedFromVersion ([string]$release.previous_version) `
        -ExpectedToVersion ([string]$release.version) `
        -LegacyApplyScriptName (ConvertTo-KaevCmsPlatformPath -Path ([string]$release.previous_apply_script)) `
        -LegacyApplySha256 ([string]$release.previous_apply_sha256)
    Assert-Equal -Actual $markerResult.Version -Expected ([string]$release.version) -Message 'Installed version marker was not read.'
    Assert-Equal -Actual $markerResult.Source -Expected 'marker' -Message 'Installed version marker source was not reported.'
    Remove-Item -LiteralPath $markerPath -Force

    $legacyPath = Join-Path $tempRoot (ConvertTo-KaevCmsPlatformPath -Path ([string]$release.previous_apply_script))
    New-Item -Path (Split-Path -Parent $legacyPath) -ItemType Directory -Force | Out-Null
    'official previous apply script fixture' | Set-Content -LiteralPath $legacyPath -Encoding UTF8
    $legacyHash = (Get-FileHash -LiteralPath $legacyPath -Algorithm SHA256).Hash.ToLowerInvariant()
    $legacyResult = Get-KaevCmsInstalledVersion `
        -ProjectRoot $tempRoot `
        -ExpectedFromVersion ([string]$release.previous_version) `
        -ExpectedToVersion ([string]$release.version) `
        -LegacyApplyScriptName (ConvertTo-KaevCmsPlatformPath -Path ([string]$release.previous_apply_script)) `
        -LegacyApplySha256 $legacyHash
    Assert-Equal -Actual $legacyResult.Version -Expected ([string]$release.previous_version) -Message 'Legacy apply fingerprint did not identify the source version.'
    Assert-Equal -Actual $legacyResult.Source -Expected 'legacy-apply-fingerprint' -Message 'Legacy apply fingerprint source was not reported.'

    New-Item -Path (Join-Path $tempRoot 'resources\views\account') -ItemType Directory -Force | Out-Null
    'legacy view' | Set-Content -LiteralPath (Join-Path $tempRoot 'resources\views\account\index.blade.php') -Encoding UTF8
    $backup = Move-KaevCmsArtifactsToBackup `
        -ProjectRoot $tempRoot `
        -TargetVersion ([string]$release.version) `
        -RelativePaths @((ConvertTo-KaevCmsPlatformPath -Path ([string]$release.previous_apply_script)), 'resources\views\account')
    Assert-True (-not (Test-Path -LiteralPath $legacyPath)) 'Previous apply script was not moved out of the active tree.'
    Assert-True (Test-Path -LiteralPath (Join-Path $backup.Root (ConvertTo-KaevCmsPlatformPath -Path ([string]$release.previous_apply_script)))) 'Previous apply script was not preserved in the update backup.'
    Assert-True (Test-Path -LiteralPath (Join-Path $backup.Root 'resources\views\account\index.blade.php')) 'Legacy account view was not preserved in the update backup.'
    Remove-KaevCmsUpdateBackups -ProjectRoot $tempRoot -TargetVersion ([string]$release.version)

    'cached' | Set-Content -LiteralPath (Join-Path $tempRoot 'bootstrap\cache\config.php') -Encoding UTF8
    'keep' | Set-Content -LiteralPath (Join-Path $tempRoot 'bootstrap\cache\.gitignore') -Encoding UTF8
    Clear-KaevCmsBootstrapCache -ProjectRoot $tempRoot
    Assert-True (-not (Test-Path -LiteralPath (Join-Path $tempRoot 'bootstrap\cache\config.php'))) 'PHP bootstrap cache was not removed.'
    Assert-True (Test-Path -LiteralPath (Join-Path $tempRoot 'bootstrap\cache\.gitignore')) 'Non-PHP bootstrap cache file was removed.'

    $contractFixture = [ordered]@{
        schema = 1
        version = '9.9.9'
        previous_version = '9.9.8'
        released_at = '2026-07-27'
        recovery_floor_version = '9.9.0'
        cumulative_base_version = '9.9.0'
        apply_script = 'deployment/windows/apply-9.9.9.ps1'
        update_script = 'deployment/windows/update.ps1'
        previous_apply_script = 'deployment/windows/apply-9.9.8.ps1'
        previous_apply_sha256 = (('0' * 64) -join '')
        composer_lock = [ordered]@{
            previous_sha256 = (('1' * 64) -join '')
            current_sha256 = (('1' * 64) -join '')
        }
    }
    $contractFixture | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $tempRoot 'release.json') -Encoding UTF8
    '9.9.8' | Set-Content -LiteralPath (Join-Path $tempRoot 'VERSION') -Encoding UTF8
    $mismatchRejected = $false
    try {
        Get-KaevCmsReleaseContract -ProjectRoot $tempRoot | Out-Null
    } catch {
        $mismatchRejected = $true
    }
    Assert-True $mismatchRejected 'A VERSION mismatch was accepted by the release contract loader.'

    '9.9.9' | Set-Content -LiteralPath (Join-Path $tempRoot 'VERSION') -Encoding UTF8
    $contractFixture.released_at = '2026-02-30'
    $contractFixture | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $tempRoot 'release.json') -Encoding UTF8
    $invalidDateRejected = $false
    try {
        Get-KaevCmsReleaseContract -ProjectRoot $tempRoot | Out-Null
    } catch {
        $invalidDateRejected = $true
    }
    Assert-True $invalidDateRejected 'An invalid release date was accepted.'

    $contractFixture.released_at = '2026-07-27'
    $contractFixture.update_script = 'deployment/windows/custom-update.ps1'
    $contractFixture | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $tempRoot 'release.json') -Encoding UTF8
    $invalidUpdateScriptRejected = $false
    try {
        Get-KaevCmsReleaseContract -ProjectRoot $tempRoot | Out-Null
    } catch {
        $invalidUpdateScriptRejected = $true
    }
    Assert-True $invalidUpdateScriptRejected 'A non-canonical update script was accepted.'

    $contractFixture.update_script = 'deployment/windows/update.ps1'
    $contractFixture.cumulative_base_version = '9.9.1'
    $contractFixture.recovery_floor_version = '9.9.0'
    $contractFixture | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $tempRoot 'release.json') -Encoding UTF8
    $invalidVersionBoundsRejected = $false
    try {
        Get-KaevCmsReleaseContract -ProjectRoot $tempRoot | Out-Null
    } catch {
        $invalidVersionBoundsRejected = $true
    }
    Assert-True $invalidVersionBoundsRejected 'Invalid release version boundaries were accepted.'

    $requiredFixtureRoot = Join-Path $tempRoot 'required-files-fixture'
    New-Item -Path (Join-Path $requiredFixtureRoot 'deployment') -ItemType Directory -Force | Out-Null
    [ordered]@{
        schema = 1
        required_files = @('present.txt', 'missing.txt')
    } | ConvertTo-Json -Depth 3 | Set-Content -LiteralPath (Join-Path $requiredFixtureRoot 'deployment/release-files.json') -Encoding UTF8
    'present' | Set-Content -LiteralPath (Join-Path $requiredFixtureRoot 'present.txt') -Encoding UTF8
    $missingReleaseFileRejected = $false
    try {
        Assert-KaevCmsRequiredReleaseFiles -ProjectRoot $requiredFixtureRoot
    } catch {
        $missingReleaseFileRejected = $true
    }
    Assert-True $missingReleaseFileRejected 'A missing required release file was accepted.'

    Write-Host 'PowerShell update workflow tests completed successfully.' -ForegroundColor Green
} finally {
    if (Test-Path -LiteralPath $tempRoot) {
        Remove-Item -LiteralPath $tempRoot -Recurse -Force
    }
}
