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

$tempRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('kaevcms-update-test-' + [guid]::NewGuid().ToString('N'))
try {
    New-Item -Path $tempRoot -ItemType Directory -Force | Out-Null

    $historyPath = Join-Path $tempRoot 'deployment\updates\deletions.json'
    New-Item -Path (Split-Path -Parent $historyPath) -ItemType Directory -Force | Out-Null
    [ordered]@{
        '0.34.9' = @('core/deployment/windows/apply-0.34.8.ps1')
        '0.35.0' = @('core/deployment/windows/apply-0.34.9.ps1')
        '0.36.0' = @('core/deployment/windows/apply-0.35.0.ps1')
        '0.36.1' = @('core/deployment/windows/apply-0.36.0.ps1')
        '0.36.2' = @('core/deployment/windows/apply-0.36.1.ps1')
        '0.36.3' = @('core/deployment/windows/apply-0.36.2.ps1')
        '0.36.4' = @('core/deployment/windows/apply-0.36.3.ps1')
        '0.36.5' = @('core/deployment/windows/apply-0.36.4.ps1')
        '0.36.6' = @('core/deployment/windows/apply-0.36.5.ps1')
        '0.36.7' = @('core/deployment/windows/apply-0.36.6.ps1')
        '0.37.0' = @('core/deployment/windows/apply-0.36.7.ps1')
        '0.37.1' = @('core/deployment/windows/apply-0.37.0.ps1')
        '0.37.2' = @('core/deployment/windows/apply-0.37.1.ps1')
        '0.37.3' = @('core/deployment/windows/apply-0.37.2.ps1')
    } | ConvertTo-Json | Set-Content -LiteralPath $historyPath -Encoding UTF8

    $recoveryLineage = Get-KaevCmsRecoveryLineage `
        -ProjectRoot $tempRoot `
        -RecoveryFloorVersion '0.34.9' `
        -ExpectedFromVersion '0.37.3' `
        -ExpectedToVersion '0.37.4'
    Assert-True (($recoveryLineage.RecoverableFromVersions -join ',') -eq '0.34.9,0.35.0,0.36.0,0.36.1,0.36.2,0.36.3,0.36.4,0.36.5,0.36.6,0.36.7,0.37.0,0.37.1,0.37.2') 'Recovery source versions were not derived from release history.'
    Assert-True (($recoveryLineage.SupersededPendingTargets -join ',') -eq '0.35.0,0.36.0,0.36.1,0.36.2,0.36.3,0.36.4,0.36.5,0.36.6,0.36.7,0.37.0,0.37.1,0.37.2,0.37.3') 'Superseded pending targets were not derived from release history.'

    Write-KaevCmsPendingUpdateMarker -ProjectRoot $tempRoot -FromVersion '0.34.9' -ToVersion '0.35.0'
    $historicalPendingConverted = $false
    foreach ($pendingFromVersion in (@('0.37.3') + $recoveryLineage.RecoverableFromVersions | Select-Object -Unique)) {
        if (Convert-KaevCmsSupersededPendingUpdateMarker `
            -ProjectRoot $tempRoot `
            -ExpectedFromVersion $pendingFromVersion `
            -ExpectedToVersion '0.37.4' `
            -SupersededToVersions $recoveryLineage.SupersededPendingTargets) {
            $historicalPendingConverted = $true
            break
        }
    }
    Assert-True $historicalPendingConverted 'Updater does not recover an interrupted 0.35.0 update from 0.34.9.'
    $historicalPending = Get-KaevCmsInstalledVersion `
        -ProjectRoot $tempRoot `
        -ExpectedFromVersion '0.34.9' `
        -ExpectedToVersion '0.37.4' `
        -LegacyApplyScriptName 'deployment\windows\apply-0.34.9.ps1' `
        -LegacyApplySha256 '0000000000000000000000000000000000000000000000000000000000000000'
    Assert-True ($historicalPending.Version -eq '0.34.9') 'Historical pending recovery did not preserve the committed source version.'
    Assert-True ($historicalPending.Source -eq 'pending-update') 'Historical pending recovery did not report the pending-update source.'
    Remove-KaevCmsPendingUpdateMarker -ProjectRoot $tempRoot

    Initialize-KaevCmsRuntimeDirectories -ProjectRoot $tempRoot
    foreach ($runtimeDirectory in @(
        'bootstrap\cache',
        'storage\app\private',
        'storage\app\public',
        'storage\framework\cache\data',
        'storage\framework\sessions',
        'storage\framework\views',
        'storage\logs'
    )) {
        Assert-True (Test-Path -LiteralPath (Join-Path $tempRoot $runtimeDirectory) -PathType Container) "Runtime directory was not created: $runtimeDirectory"
    }
    New-Item -Path (Join-Path $tempRoot 'storage\app\kaevcms') -ItemType Directory -Force | Out-Null

    $markerPath = Join-Path $tempRoot 'storage\app\kaevcms\installed-version.json'
    '{"version":"0.33.0"}' | Set-Content -LiteralPath $markerPath -Encoding UTF8
    $markerResult = Get-KaevCmsInstalledVersion -ProjectRoot $tempRoot -ExpectedFromVersion '0.32.20' -ExpectedToVersion '0.33.0' -LegacyApplyScriptName 'deployment\windows\apply-0.32.20.ps1' -LegacyApplySha256 '0000000000000000000000000000000000000000000000000000000000000000'
    Assert-True ($markerResult.Version -eq '0.33.0') 'Marker version was not read.'
    Assert-True ($markerResult.Source -eq 'marker') 'Marker source was not reported.'

    Remove-Item -LiteralPath $markerPath -Force
    $legacyPath = Join-Path $tempRoot 'deployment\windows\apply-0.32.20.ps1'
    New-Item -Path (Split-Path -Parent $legacyPath) -ItemType Directory -Force | Out-Null
    'official previous apply script' | Set-Content -LiteralPath $legacyPath -Encoding UTF8
    $legacyHash = (Get-FileHash -LiteralPath $legacyPath -Algorithm SHA256).Hash.ToLowerInvariant()
    $legacyResult = Get-KaevCmsInstalledVersion -ProjectRoot $tempRoot -ExpectedFromVersion '0.32.20' -ExpectedToVersion '0.33.0' -LegacyApplyScriptName 'deployment\windows\apply-0.32.20.ps1' -LegacyApplySha256 $legacyHash
    Assert-True ($legacyResult.Version -eq '0.32.20') 'Legacy source version was not reported.'
    Assert-True ($legacyResult.Source -eq 'legacy-apply-fingerprint') 'Legacy source fingerprint was not accepted.'

    Write-KaevCmsPendingUpdateMarker -ProjectRoot $tempRoot -FromVersion '0.32.20' -ToVersion '0.33.0'
    Remove-Item -LiteralPath $legacyPath -Force
    $pendingResult = Get-KaevCmsInstalledVersion -ProjectRoot $tempRoot -ExpectedFromVersion '0.32.20' -ExpectedToVersion '0.33.0' -LegacyApplyScriptName 'deployment\windows\apply-0.32.20.ps1' -LegacyApplySha256 $legacyHash
    Assert-True ($pendingResult.Version -eq '0.32.20') 'Pending update source version was not read.'
    Assert-True ($pendingResult.Source -eq 'pending-update') 'Pending update source was not reported.'

    Remove-KaevCmsPendingUpdateMarker -ProjectRoot $tempRoot
    Write-KaevCmsPendingUpdateMarker -ProjectRoot $tempRoot -FromVersion '0.32.20' -ToVersion '0.33.0-candidate.1'
    $converted = Convert-KaevCmsSupersededPendingUpdateMarker `
        -ProjectRoot $tempRoot `
        -ExpectedFromVersion '0.32.20' `
        -ExpectedToVersion '0.33.0' `
        -SupersededToVersions @('0.33.0-candidate.1')
    Assert-True $converted 'Generic superseded pending update marker was not adopted.'
    $adoptedResult = Get-KaevCmsInstalledVersion -ProjectRoot $tempRoot -ExpectedFromVersion '0.32.20' -ExpectedToVersion '0.33.0' -LegacyApplyScriptName 'deployment\windows\apply-0.32.20.ps1' -LegacyApplySha256 $legacyHash
    Assert-True ($adoptedResult.Version -eq '0.32.20') 'Adopted pending update did not preserve the source version.'
    Assert-True ($adoptedResult.Source -eq 'pending-update') 'Adopted pending update source was not reported.'

    Remove-KaevCmsPendingUpdateMarker -ProjectRoot $tempRoot
    Write-KaevCmsPendingUpdateMarker -ProjectRoot $tempRoot -FromVersion '0.32.20' -ToVersion '0.33.0'
    $hotfixConverted = Convert-KaevCmsSupersededPendingUpdateMarker `
        -ProjectRoot $tempRoot `
        -ExpectedFromVersion '0.32.20' `
        -ExpectedToVersion '0.33.1' `
        -SupersededToVersions @('0.33.0')
    Assert-True $hotfixConverted 'Interrupted 0.33.0 pending marker was not adopted by the 0.33.1 hotfix.'
    $hotfixResult = Get-KaevCmsInstalledVersion -ProjectRoot $tempRoot -ExpectedFromVersion '0.32.20' -ExpectedToVersion '0.33.1' -LegacyApplyScriptName 'deployment\windows\apply-0.33.0.ps1' -LegacyApplySha256 $legacyHash
    Assert-True ($hotfixResult.Version -eq '0.32.20') 'Hotfix recovery did not preserve the last committed source version.'
    Assert-True ($hotfixResult.Source -eq 'pending-update') 'Hotfix recovery source was not reported.'

    Remove-KaevCmsPendingUpdateMarker -ProjectRoot $tempRoot
    Write-KaevCmsPendingUpdateMarker -ProjectRoot $tempRoot -FromVersion '0.32.20' -ToVersion '0.33.0-foreign.1'

    $wrongTargetRejected = $false
    try {
        Get-KaevCmsInstalledVersion -ProjectRoot $tempRoot -ExpectedFromVersion '0.32.20' -ExpectedToVersion '0.33.0' -LegacyApplyScriptName 'deployment\windows\apply-0.32.20.ps1' -LegacyApplySha256 $legacyHash | Out-Null
    } catch {
        $wrongTargetRejected = $true
    }
    Assert-True $wrongTargetRejected 'Pending marker for another target release was accepted.'
    Remove-KaevCmsPendingUpdateMarker -ProjectRoot $tempRoot

    'official previous apply script' | Set-Content -LiteralPath $legacyPath -Encoding UTF8
    $hashRejected = $false
    try {
        Get-KaevCmsInstalledVersion -ProjectRoot $tempRoot -ExpectedFromVersion '0.32.20' -ExpectedToVersion '0.33.0' -LegacyApplyScriptName 'deployment\windows\apply-0.32.20.ps1' -LegacyApplySha256 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff' | Out-Null
    } catch {
        $hashRejected = $true
    }
    Assert-True $hashRejected 'A modified previous apply script was accepted.'

    New-Item -Path (Join-Path $tempRoot 'resources\views\account') -ItemType Directory -Force | Out-Null
    'legacy view' | Set-Content -LiteralPath (Join-Path $tempRoot 'resources\views\account\index.blade.php') -Encoding UTF8
    $backup = Move-KaevCmsArtifactsToBackup -ProjectRoot $tempRoot -TargetVersion '0.33.0' -RelativePaths @('deployment\windows\apply-0.32.20.ps1', 'resources\views\account')
    Assert-True (-not (Test-Path -LiteralPath $legacyPath)) 'Previous apply script was not moved out of the project root.'
    Assert-True (-not (Test-Path -LiteralPath (Join-Path $tempRoot 'resources\views\account'))) 'Legacy account views were not moved out of the active tree.'
    Assert-True (Test-Path -LiteralPath (Join-Path $backup.Root 'deployment\windows\apply-0.32.20.ps1')) 'Previous apply script was not preserved in the update backup.'
    Assert-True (Test-Path -LiteralPath (Join-Path $backup.Root 'resources\views\account\index.blade.php')) 'Legacy account view was not preserved in the update backup.'
    Remove-KaevCmsUpdateBackups -ProjectRoot $tempRoot -TargetVersion '0.33.0'
    Assert-True (-not (Test-Path -LiteralPath (Join-Path $tempRoot 'storage\app\kaevcms\update-backups\0.33.0'))) 'Successful update backups were not removed.'

    'cached' | Set-Content -LiteralPath (Join-Path $tempRoot 'bootstrap\cache\config.php') -Encoding UTF8
    'cached' | Set-Content -LiteralPath (Join-Path $tempRoot 'bootstrap\cache\routes.php') -Encoding UTF8
    'keep' | Set-Content -LiteralPath (Join-Path $tempRoot 'bootstrap\cache\.gitignore') -Encoding UTF8
    Clear-KaevCmsBootstrapCache -ProjectRoot $tempRoot
    Assert-True (-not (Test-Path -LiteralPath (Join-Path $tempRoot 'bootstrap\cache\config.php'))) 'PHP bootstrap cache was not removed.'
    Assert-True (Test-Path -LiteralPath (Join-Path $tempRoot 'bootstrap\cache\.gitignore')) 'Non-PHP bootstrap cache file was removed.'

    $qualityScript = Get-Content -LiteralPath "$PSScriptRoot\..\quality.ps1" -Raw
    Assert-True ($qualityScript.Contains('Initialize-KaevCmsRuntimeDirectories -ProjectRoot $ProjectRoot')) 'Quality gate does not restore required runtime directories.'

    $updateScript = Get-Content -LiteralPath "$PSScriptRoot\..\update.ps1" -Raw
    Assert-True (-not $updateScript.Contains('QUEUE_CONNECTION=sync')) 'Updater still rewrites QUEUE_CONNECTION.'
    Assert-True (-not $updateScript.Contains('SESSION_COOKIE=l2forge_session')) 'Updater still writes the legacy session cookie.'
    Assert-True (-not $updateScript.Contains('function Set-EnvValue')) 'Updater still contains an .env mutation helper.'
    Assert-True ($updateScript.Contains('$composerDependenciesChanged')) 'Updater does not compare release dependency locks.'
    Assert-True ($updateScript.Contains('Composer install was skipped')) 'Updater does not skip an unchanged Composer dependency set.'
    Assert-True ($updateScript.Contains('$actualComposerLockSha256 -ne $currentComposerLockSha256')) 'Updater does not verify the release composer.lock fingerprint.'
    Assert-True ($updateScript.Contains('php artisan kaevcms:maintenance-status --no-ansi')) 'Updater does not query Laravel for the current maintenance state.'
    Assert-True ($updateScript.Contains('Move-KaevCmsArtifactsToBackup')) 'Updater does not stage obsolete artifacts before tests.'
    Assert-True ($updateScript.Contains('if ($supersededPendingTargets.Count -gt 0)')) 'Updater does not guard an empty superseded candidate list.'
    Assert-True ($updateScript.Contains('$recoveryFloorVersion = ''0.34.9''')) 'Updater recovery floor is not defined.'
    Assert-True ($updateScript.Contains('Get-KaevCmsRecoveryLineage')) 'Updater does not derive recovery lineage from release history.'
    Assert-True ($updateScript.Contains('$recoverableFromVersions = @($recoveryLineage.RecoverableFromVersions)')) 'Updater does not use derived recovery source versions.'
    Assert-True ($updateScript.Contains('$supersededPendingTargets = @($recoveryLineage.SupersededPendingTargets)')) 'Updater does not use derived superseded pending targets.'
    $hardCodedRecoveryList = '$recoverableFromVersions = @(' + [char]39 + '0.34.9' + [char]39 + ')'
    Assert-True (-not $updateScript.Contains($hardCodedRecoveryList)) 'Updater still hard-codes recoverable source versions.'
    Assert-True ($updateScript.Contains("'public\game-assets'")) 'Updater does not remove the obsolete standard game asset root.'
    Assert-True ($updateScript.Contains("'public\uploads\game-assets\items'")) 'Updater does not recreate the canonical item image directory.'
    Assert-True ($updateScript.Contains("'public\uploads\game-assets\items\common'")) 'Updater does not create the common item directory.'
    Assert-True ($updateScript.Contains("'*ItemImporterTest.php'")) 'Updater does not clean obsolete catalog-generation tests generically.'
    Assert-True ($updateScript.Contains('$installed.Version -notin $supportedFromVersions')) 'Updater does not validate all supported hotfix source versions.'
    Assert-True ($updateScript.Contains('-FromVersion $installed.Version')) 'Updater does not preserve the actual committed source version in the new pending marker.'
    Assert-True ($updateScript.Contains("'resources\views\account'")) 'Updater does not remove legacy account views.'
    Assert-True ($updateScript.Contains("'resources\views\livewire\account'")) 'Updater does not remove legacy Livewire account views.'
    Assert-True (-not $updateScript.Contains("'public\assets\account',")) 'Updater still removes the shared account runtime directory.'
    Assert-True ($updateScript.Contains("'public\assets\admin\css\app.css'")) 'Updater does not remove the obsolete administration stylesheet.'
    Assert-True ($updateScript.Contains("'public\account-themes\luxury\assets\js\navigation.js'")) 'Updater does not remove the duplicated Luxury navigation runtime.'
    Assert-True ($updateScript.Contains("'public\account-themes\kaev-aurelia\assets\js\navigation.js'")) 'Updater does not remove the duplicated Aurelia navigation runtime.'

    $clearPosition = $updateScript.IndexOf('Clear-KaevCmsBootstrapCache -ProjectRoot $ProjectRoot')
    $maintenancePosition = $updateScript.IndexOf('php artisan down --retry=60')
    $composerPosition = $updateScript.IndexOf('composer install --no-interaction --prefer-dist --no-scripts')
    $stagePosition = $updateScript.IndexOf('Move-KaevCmsArtifactsToBackup')
    $testPosition = $updateScript.IndexOf('php artisan test')
    Assert-True ($clearPosition -ge 0 -and $composerPosition -ge 0 -and $clearPosition -lt $composerPosition) 'Bootstrap cache is not cleared before Composer.'
    Assert-True ($maintenancePosition -ge 0 -and $composerPosition -ge 0 -and $maintenancePosition -lt $composerPosition) 'Maintenance mode is not enabled before Composer changes dependencies.'
    Assert-True ($stagePosition -ge 0 -and $testPosition -ge 0 -and $stagePosition -lt $testPosition) 'Obsolete release artifacts are not staged before PHPUnit.'

    $phpunitConfig = Get-Content -LiteralPath "$PWD\phpunit.xml" -Raw
    Assert-True ($phpunitConfig.Contains('<env name="APP_MAINTENANCE_DRIVER" value="cache" force="true"/>')) 'PHPUnit still shares the live file maintenance state.'
    Assert-True ($phpunitConfig.Contains('<env name="APP_MAINTENANCE_STORE" value="array" force="true"/>')) 'PHPUnit maintenance cache is not isolated in memory.'

    $releaseVersion = (Get-Content -LiteralPath (Join-Path $ProjectRoot 'VERSION') -Raw).Trim()
    $applyScriptPath = Join-Path $PSScriptRoot "..\apply-$releaseVersion.ps1"
    Assert-True (Test-Path -LiteralPath $applyScriptPath -PathType Leaf) "Current apply script is missing: apply-$releaseVersion.ps1"
    $applyScript = Get-Content -LiteralPath $applyScriptPath -Raw
    Assert-True (-not $applyScript.Contains('update.ps1 failed with exit code $LASTEXITCODE')) 'Apply script still relies on a stale LASTEXITCODE after invoking PowerShell.'
    Assert-True ($applyScript.Contains('public\assets\admin\css\base.css')) 'Current apply script does not require the split administration stylesheets.'
    Assert-True ($applyScript.Contains('public\assets\admin\css\catalogs.css')) 'Current apply script does not require the final administration stylesheet.'
    Assert-True ($applyScript.Contains('public\assets\account\js\navigation.js')) 'Current apply script does not require the shared account navigation runtime.'
    Assert-True (-not $applyScript.Contains('public\assets\admin\css\app.css')) 'Current apply script still requires the removed administration stylesheet.'

    Write-Host 'PowerShell update workflow tests completed successfully.' -ForegroundColor Green
} finally {
    if (Test-Path -LiteralPath $tempRoot) {
        Remove-Item -LiteralPath $tempRoot -Recurse -Force
    }
}
