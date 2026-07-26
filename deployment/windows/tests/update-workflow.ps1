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
    Assert-True ($updateScript.Contains("$recoverableFromVersions = @('0.34.7')")) 'Updater does not recover an interrupted 0.34.8 update from 0.34.7.'
    Assert-True ($updateScript.Contains("$supersededPendingTargets = @('0.34.8')")) 'Updater does not adopt the interrupted 0.34.8 pending target.'
    Assert-True (-not $updateScript.Contains("'public\game-assets\items\interlude'")) 'Updater must never delete the external Interlude image pack.'
    Assert-True ($updateScript.Contains("'*ItemImporterTest.php'")) 'Updater does not clean obsolete catalog-generation tests generically.'
    Assert-True ($updateScript.Contains('$installed.Version -notin $supportedFromVersions')) 'Updater does not validate all supported hotfix source versions.'
    Assert-True ($updateScript.Contains('-FromVersion $installed.Version')) 'Updater does not preserve the actual committed source version in the new pending marker.'
    Assert-True ($updateScript.Contains("'resources\views\account'")) 'Updater does not remove legacy account views.'
    Assert-True ($updateScript.Contains("'resources\views\livewire\account'")) 'Updater does not remove legacy Livewire account views.'
    Assert-True ($updateScript.Contains("'public\assets\account'")) 'Updater does not remove legacy account assets.'

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

    $applyScript = Get-Content -LiteralPath "$PSScriptRoot\..\apply-0.34.9.ps1" -Raw
    Assert-True (-not $applyScript.Contains('update.ps1 failed with exit code $LASTEXITCODE')) 'Apply script still relies on a stale LASTEXITCODE after invoking PowerShell.'

    Write-Host 'PowerShell update workflow tests completed successfully.' -ForegroundColor Green
} finally {
    if (Test-Path -LiteralPath $tempRoot) {
        Remove-Item -LiteralPath $tempRoot -Recurse -Force
    }
}
