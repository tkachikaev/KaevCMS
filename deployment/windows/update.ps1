param(
    [switch]$SkipTests
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
Set-Location -LiteralPath $ProjectRoot

$expectedFromVersion = '0.36.6'
$expectedToVersion = '0.36.7'
$legacyApplyScriptName = 'deployment\windows\apply-0.36.6.ps1'
$legacyApplySha256 = '6ffbfcb7174b8a32974f421b69cd0ddb0a512527a1d14ea5adcdb497ed0740e1'
$previousComposerLockSha256 = '53bb4fc6ea6a488af1bdbf428afcd1086dcabca9613b54f11c06700abe100ab4'
$currentComposerLockSha256 = '53bb4fc6ea6a488af1bdbf428afcd1086dcabca9613b54f11c06700abe100ab4'
$recoveryFloorVersion = '0.34.9'

$supportScript = Join-Path $PSScriptRoot 'support\release-update-support.ps1'
if (-not (Test-Path -LiteralPath $supportScript -PathType Leaf)) {
    throw 'Release update support script is missing. Re-extract the complete release or patch.'
}
. $supportScript

$recoveryLineage = Get-KaevCmsRecoveryLineage `
    -ProjectRoot $ProjectRoot `
    -RecoveryFloorVersion $recoveryFloorVersion `
    -ExpectedFromVersion $expectedFromVersion `
    -ExpectedToVersion $expectedToVersion
$recoverableFromVersions = @($recoveryLineage.RecoverableFromVersions)
$supersededPendingTargets = @($recoveryLineage.SupersededPendingTargets)

function Write-UpdateStage {
    param(
        [Parameter(Mandatory = $true)][string]$Message,
        [ValidateSet('INFO', 'WARN', 'ERROR')][string]$Level = 'INFO'
    )

    $line = "[{0}] [{1}] {2}" -f (Get-Date).ToString('s'), $Level, $Message
    Add-Content -LiteralPath $script:updateLogPath -Value $line -Encoding UTF8

    if ($Level -eq 'WARN') {
        Write-Host $Message -ForegroundColor Yellow
    } elseif ($Level -eq 'ERROR') {
        Write-Host $Message -ForegroundColor Red
    } else {
        Write-Host $Message
    }
}

function Invoke-Checked {
    param(
        [Parameter(Mandatory = $true)][string]$Label,
        [Parameter(Mandatory = $true)][scriptblock]$Command
    )

    Write-UpdateStage -Message "Starting: $Label"
    $global:LASTEXITCODE = 0
    & $Command
    $exitCode = $LASTEXITCODE
    if ($exitCode -ne 0) {
        throw "$Label failed with exit code $exitCode."
    }
    Write-UpdateStage -Message "Completed: $Label"
}

function Write-WebInstallerLock {
    param([Parameter(Mandatory = $true)][string]$Version)

    $lockPath = Join-Path $ProjectRoot 'storage\app\installed.lock'
    New-Item -Path (Split-Path -Parent $lockPath) -ItemType Directory -Force | Out-Null
    [ordered]@{
        version = $Version
        installed_at = (Get-Date).ToUniversalTime().ToString('o')
        source = 'windows-update'
    } | ConvertTo-Json | Set-Content -LiteralPath $lockPath -Encoding UTF8
}

function Get-EnvValue {
    param(
        [string]$Path,
        [string]$Name,
        [string]$Default = ''
    )

    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        return $Default
    }

    $escapedName = [regex]::Escape($Name)
    $line = Get-Content -LiteralPath $Path |
        Where-Object { $_ -match "^\s*$escapedName\s*=" } |
        Select-Object -First 1

    if ($null -eq $line) {
        return $Default
    }

    $value = (($line -split '=', 2)[1]).Trim()
    if ($value.Length -ge 2) {
        $first = $value[0]
        $last = $value[$value.Length - 1]
        if (($first -eq [char]34 -and $last -eq [char]34) -or ($first -eq [char]39 -and $last -eq [char]39)) {
            $value = $value.Substring(1, $value.Length - 2)
        }
    }

    return $value
}

function Get-PasswordAlgorithmName {
    param([string]$Driver)

    switch ($Driver.Trim().ToLowerInvariant()) {
        'bcrypt' { return '2y' }
        'argon' { return 'argon2i' }
        'argon2id' { return 'argon2id' }
        default { return $null }
    }
}

function Test-PasswordHashDriver {
    param([string]$Driver)

    $algorithm = Get-PasswordAlgorithmName -Driver $Driver
    if ([string]::IsNullOrWhiteSpace($algorithm)) {
        return $false
    }

    $output = & php -r "echo in_array('$algorithm', password_algos(), true) ? '1' : '0';"
    if ($LASTEXITCODE -ne 0) {
        return $false
    }

    return ([string]$output).Trim() -eq '1'
}

function Resolve-PasswordHashDriver {
    param([string]$Driver)

    $requested = $Driver.Trim().ToLowerInvariant()
    if ($requested -eq 'auto') {
        if (Test-PasswordHashDriver -Driver 'argon2id') { return 'argon2id' }
        if (Test-PasswordHashDriver -Driver 'bcrypt') { return 'bcrypt' }

        return $null
    }

    if (Test-PasswordHashDriver -Driver $requested) {
        return $requested
    }

    return $null
}

function Get-ObsoleteReleaseArtifacts {
    param([Parameter(Mandatory = $true)][string]$CurrentVersion)

    $currentApplyScript = "apply-$CurrentVersion.ps1"
    $paths = @(
        'preview',
        'resources\views\admin\settings\placeholder.blade.php',
        'app\Http\Controllers\Admin\SettingsController.php',
        'resources\views\account',
        'resources\views\livewire\account',
        'public\assets\account',
        'public\game-assets',
        'integrations\mobius-interlude\reward-bridge',
        'integrations\reward-queue\remove-legacy-bridge.sql',
        'app\Contracts\GameRewardDeliveryGateway.php',
        'app\Jobs\ConfirmRewardDelivery.php',
        'app\Jobs\ProcessRewardDelivery.php',
        'app\Services\Rewards\DriverGameRewardDeliveryGateway.php',
        'app\Services\Rewards\RewardDeliveryProcessor.php',
        'app\Support\Rewards\RewardDeliveryCapabilities.php',
        'app\Support\Rewards\RewardDeliveryPayload.php',
        'app\Support\Rewards\RewardDeliveryResult.php',
        'tests\Fakes\FakeGameRewardDeliveryGateway.php',
        'tests\Feature\Rewards\MobiusRewardBridgeDriverTest.php',
        'app\Services\GameWorld\MobiusInterludeGameWorldDriver.php',
        'app\Services\GameWorld\InterludeCharacterLabels.php',
        'app\Services\GameAccounts\InterludeClassNames.php',
        'app\Console\Commands\ImportInterludeItemsCommand.php',
        'app\Services\GameAssets\Import\InterludeItemImporter.php',
        'tests\Unit\InterludeItemImporterTest.php',
        'app\Console\Commands\ImportGameItemsCommand.php',
        'app\Services\GameAssets\Import',
        'app\Contracts\GameServerAdapter.php',
        'app\Services\GameServer\MobiusGameServerAdapter.php',
        'app\Services\GameServer\MockGameServerAdapter.php',
        'config\game.php',
        'apply-0.31.7.ps1',
        'browser-quality.ps1',
        'browser-setup.ps1',
        'doctor.ps1',
        'quality.ps1',
        'security-audit.ps1',
        'serve.ps1',
        'setup.ps1',
        'update.ps1',
        'scripts',
        'tests\powershell'
    )

    $obsoleteApplyScripts = Get-ChildItem -LiteralPath $PSScriptRoot -Filter 'apply-*.ps1' -File -ErrorAction Stop |
        Where-Object { $_.Name -ne $currentApplyScript }

    foreach ($obsoleteApplyScript in $obsoleteApplyScripts) {
        $paths += 'deployment\windows\' + $obsoleteApplyScript.Name
    }

    $unitTestPath = Join-Path $ProjectRoot 'tests\Unit'
    if (Test-Path -LiteralPath $unitTestPath -PathType Container) {
        $obsoleteItemImporterTests = Get-ChildItem -LiteralPath $unitTestPath -Filter '*ItemImporterTest.php' -File -ErrorAction Stop
        foreach ($obsoleteItemImporterTest in $obsoleteItemImporterTests) {
            $paths += 'tests\Unit\' + $obsoleteItemImporterTest.Name
        }
    }

    return @($paths | Select-Object -Unique)
}

function Remove-ObsoleteReleaseArtifacts {
    param([Parameter(Mandatory = $true)][string]$CurrentVersion)

    foreach ($obsoletePath in (Get-ObsoleteReleaseArtifacts -CurrentVersion $CurrentVersion)) {
        $fullPath = Join-Path $ProjectRoot $obsoletePath
        if (Test-Path -LiteralPath $fullPath) {
            Remove-Item -LiteralPath $fullPath -Recurse -Force -ErrorAction Stop
            Write-UpdateStage -Message "Removed obsolete release artifact: $obsoletePath"
        }
    }
}

if (-not (Test-Path 'VERSION' -PathType Leaf)) {
    throw 'VERSION is missing. Re-extract the complete KaevCMS release or patch.'
}

$cmsVersion = (Get-Content 'VERSION' -Raw).Trim()
if (-not (Test-KaevCmsVersion -Version $cmsVersion)) {
    throw "VERSION contains an invalid release number: $cmsVersion"
}
if ($cmsVersion -ne $expectedToVersion) {
    throw "This updater expects KaevCMS $expectedToVersion, but VERSION contains $cmsVersion."
}

if (-not (Test-Path '.env' -PathType Leaf)) {
    throw '.env is missing. Run .\deployment\windows\setup.ps1 or use /install/ for a new installation.'
}
if (-not (Test-Path 'composer.lock' -PathType Leaf)) {
    throw 'composer.lock is missing. Re-extract the complete KaevCMS release or patch.'
}
$actualComposerLockSha256 = (Get-FileHash -LiteralPath 'composer.lock' -Algorithm SHA256).Hash.ToLowerInvariant()
if ($actualComposerLockSha256 -ne $currentComposerLockSha256) {
    throw 'composer.lock does not match this KaevCMS release. Re-extract the complete release or patch.'
}
$composerDependenciesChanged = $previousComposerLockSha256 -ne $currentComposerLockSha256
if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    throw 'PHP was not found in PATH.'
}
if (-not (Get-Command composer -ErrorAction SilentlyContinue)) {
    throw 'Composer was not found in PATH.'
}
$directories = @(
    'bootstrap\cache',
    'storage\app\kaevcms',
    'storage\framework\cache\data',
    'storage\framework\sessions',
    'storage\framework\views',
    'storage\logs',
    'public\uploads\news\covers',
    'public\uploads\news\content',
    'public\uploads\pages\content',
    'public\uploads\settings\logo',
    'public\uploads\settings\favicon',
    'public\uploads\account-avatars',
    'public\uploads\game-assets',
    'public\uploads\game-assets\items',
    'public\uploads\game-assets\items\common',
    'public\uploads\game-assets\items\servers',
    'public\uploads\game-assets\characters',
    'public\uploads\game-assets\characters\common',
    'public\uploads\game-assets\characters\common\human\male',
    'public\uploads\game-assets\characters\common\human\female',
    'public\uploads\game-assets\characters\common\human\neutral',
    'public\uploads\game-assets\characters\common\elf\male',
    'public\uploads\game-assets\characters\common\elf\female',
    'public\uploads\game-assets\characters\common\elf\neutral',
    'public\uploads\game-assets\characters\common\dark_elf\male',
    'public\uploads\game-assets\characters\common\dark_elf\female',
    'public\uploads\game-assets\characters\common\dark_elf\neutral',
    'public\uploads\game-assets\characters\common\orc\male',
    'public\uploads\game-assets\characters\common\orc\female',
    'public\uploads\game-assets\characters\common\orc\neutral',
    'public\uploads\game-assets\characters\common\dwarf\male',
    'public\uploads\game-assets\characters\common\dwarf\female',
    'public\uploads\game-assets\characters\common\dwarf\neutral',
    'public\uploads\game-assets\characters\common\kamael\male',
    'public\uploads\game-assets\characters\common\kamael\female',
    'public\uploads\game-assets\characters\common\kamael\neutral',
    'public\uploads\game-assets\characters\common\ertheia\male',
    'public\uploads\game-assets\characters\common\ertheia\female',
    'public\uploads\game-assets\characters\common\ertheia\neutral',
    'public\uploads\game-assets\characters\common\sylph\male',
    'public\uploads\game-assets\characters\common\sylph\female',
    'public\uploads\game-assets\characters\common\sylph\neutral',
    'public\uploads\game-assets\characters\common\fallback\male',
    'public\uploads\game-assets\characters\common\fallback\female',
    'public\uploads\game-assets\characters\common\fallback\neutral',
    'public\uploads\game-assets\characters\servers'
)
foreach ($directory in $directories) {
    New-Item -Path $directory -ItemType Directory -Force | Out-Null
}

$script:updateLogPath = Join-Path $ProjectRoot ('storage\logs\update-{0}-{1}.log' -f $cmsVersion, (Get-Date -Format 'yyyyMMdd-HHmmss'))
Write-UpdateStage -Message "KaevCMS update target: $cmsVersion"
Write-UpdateStage -Message "Project: $ProjectRoot"

$pendingMarkerConverted = $false
if ($supersededPendingTargets.Count -gt 0) {
    foreach ($pendingFromVersion in (@($expectedFromVersion) + $recoverableFromVersions | Select-Object -Unique)) {
        if (Convert-KaevCmsSupersededPendingUpdateMarker `
            -ProjectRoot $ProjectRoot `
            -ExpectedFromVersion $pendingFromVersion `
            -ExpectedToVersion $expectedToVersion `
            -SupersededToVersions $supersededPendingTargets) {
            $pendingMarkerConverted = $true
            break
        }
    }
}
if ($pendingMarkerConverted) {
    Write-UpdateStage -Message "A pending marker from a superseded release was adopted for $expectedToVersion." -Level WARN
}

$verificationFromVersion = $expectedFromVersion
$pendingMarkerPath = Get-KaevCmsPendingUpdateMarkerPath -ProjectRoot $ProjectRoot
if (Test-Path -LiteralPath $pendingMarkerPath -PathType Leaf) {
    try {
        $pendingMarker = Get-Content -LiteralPath $pendingMarkerPath -Raw | ConvertFrom-Json -ErrorAction Stop
        $pendingFromVersion = [string]$pendingMarker.from_version
        $pendingToVersion = [string]$pendingMarker.to_version
        $supportedPendingSources = @($expectedFromVersion) + $recoverableFromVersions
        if ($pendingToVersion -eq $expectedToVersion -and $supportedPendingSources -contains $pendingFromVersion) {
            $verificationFromVersion = $pendingFromVersion
        }
    } catch {
        throw "Pending update marker is invalid: $pendingMarkerPath"
    }
}

$installed = Get-KaevCmsInstalledVersion `
    -ProjectRoot $ProjectRoot `
    -ExpectedFromVersion $verificationFromVersion `
    -ExpectedToVersion $expectedToVersion `
    -LegacyApplyScriptName $legacyApplyScriptName `
    -LegacyApplySha256 $legacyApplySha256

if ($installed.Version -eq $cmsVersion) {
    Write-UpdateStage -Message "KaevCMS $cmsVersion is already recorded as installed. Running final cleanup only."
    Remove-ObsoleteReleaseArtifacts -CurrentVersion $cmsVersion
    Remove-KaevCmsPendingUpdateMarker -ProjectRoot $ProjectRoot
    Remove-KaevCmsUpdateBackups -ProjectRoot $ProjectRoot -TargetVersion $cmsVersion
    Write-WebInstallerLock -Version $cmsVersion
    return
}
$supportedFromVersions = @($expectedFromVersion) + $recoverableFromVersions
if ($installed.Version -notin $supportedFromVersions) {
    throw "This update requires KaevCMS $expectedFromVersion. Installed version: $($installed.Version)."
}
Write-UpdateStage -Message "KaevCMS $($installed.Version) -> $cmsVersion update"
Write-UpdateStage -Message "Verified installed version $($installed.Version) using $($installed.Source)."

Write-KaevCmsPendingUpdateMarker `
    -ProjectRoot $ProjectRoot `
    -FromVersion $installed.Version `
    -ToVersion $cmsVersion

$obsoleteArtifacts = Get-ObsoleteReleaseArtifacts -CurrentVersion $cmsVersion
$backup = Move-KaevCmsArtifactsToBackup `
    -ProjectRoot $ProjectRoot `
    -TargetVersion $cmsVersion `
    -RelativePaths $obsoleteArtifacts
foreach ($movedPath in $backup.Paths) {
    Write-UpdateStage -Message "Moved obsolete release artifact to update backup: $movedPath"
}
if ($backup.Paths.Count -gt 0) {
    Write-UpdateStage -Message "Obsolete artifacts are preserved until successful completion: $($backup.Root)"
}

$hashDriver = (Get-EnvValue -Path '.env' -Name 'HASH_DRIVER' -Default 'auto').ToLowerInvariant()
$knownHashDrivers = @('auto', 'bcrypt', 'argon', 'argon2id')
if ($knownHashDrivers -notcontains $hashDriver) {
    throw "Unsupported HASH_DRIVER: $hashDriver. Use auto, bcrypt, argon or argon2id."
}
$effectiveHashDriver = Resolve-PasswordHashDriver -Driver $hashDriver
if ([string]::IsNullOrWhiteSpace($effectiveHashDriver)) {
    throw "No supported password hashing algorithm is available for HASH_DRIVER=$hashDriver."
}
Write-UpdateStage -Message "Password hashing: $effectiveHashDriver (requested: $hashDriver)"

$maintenanceActivated = $false
$updateError = $null

try {
    Write-UpdateStage -Message 'Clearing Laravel bootstrap cache files before Composer package discovery.'
    Clear-KaevCmsBootstrapCache -ProjectRoot $ProjectRoot

    $global:LASTEXITCODE = 0
    $maintenanceStatus = (& php artisan kaevcms:maintenance-status --no-ansi 2>&1 | Out-String).Trim()
    $maintenanceExitCode = $LASTEXITCODE
    if ($maintenanceExitCode -ne 0 -or $maintenanceStatus -notin @('up', 'down')) {
        throw "Unable to determine the current maintenance mode state. Output: $maintenanceStatus"
    }

    if ($maintenanceStatus -eq 'up') {
        Invoke-Checked 'Enabling maintenance mode' { php artisan down --retry=60 }
        $maintenanceActivated = $true
    } else {
        Write-UpdateStage -Message 'Application was already in maintenance mode; it will remain there.'
    }

    if ($composerDependenciesChanged -or -not (Test-Path 'vendor\autoload.php' -PathType Leaf)) {
        Invoke-Checked 'Installing pinned PHP dependencies without Laravel scripts' {
            composer install --no-interaction --prefer-dist --no-scripts
        }
    } else {
        Write-UpdateStage -Message 'composer.lock is unchanged and vendor is present; Composer install was skipped.'
    }

    Invoke-Checked 'Rebuilding optimized autoload and discovering packages' {
        composer dump-autoload --optimize --no-interaction
    }
    Invoke-Checked 'Clearing Laravel runtime caches' { php artisan optimize:clear }
    Invoke-Checked 'Running database migrations' { php artisan migrate --force }
    Invoke-Checked 'Signalling queue workers to restart' { php artisan queue:restart }
    Invoke-Checked 'Refreshing server monitoring snapshot' { php artisan kaevcms:servers-monitor --force }

    if (-not $SkipTests) {
        Invoke-Checked 'Running automated tests' { php artisan test }
    } else {
        Write-UpdateStage -Message 'Automated tests were skipped by explicit request.' -Level WARN
    }

    Invoke-Checked 'Recording installed release version' {
        php artisan kaevcms:release-version --mark=$cmsVersion
    }
    Write-WebInstallerLock -Version $cmsVersion
    Write-UpdateStage -Message 'Web installer lock refreshed.'

    Remove-KaevCmsPendingUpdateMarker -ProjectRoot $ProjectRoot
    Remove-KaevCmsUpdateBackups -ProjectRoot $ProjectRoot -TargetVersion $cmsVersion
    foreach ($supersededPendingTarget in $supersededPendingTargets) {
        Remove-KaevCmsUpdateBackups -ProjectRoot $ProjectRoot -TargetVersion $supersededPendingTarget
    }
    Remove-ObsoleteReleaseArtifacts -CurrentVersion $cmsVersion
    Write-UpdateStage -Message "KaevCMS $cmsVersion update completed successfully."
} catch {
    $updateError = $_
    Write-UpdateStage -Message $_.Exception.Message -Level ERROR
    Write-UpdateStage -Message 'The verified source marker and update backups were kept so the updater can be resumed safely.' -Level WARN
} finally {
    if ($maintenanceActivated) {
        $global:LASTEXITCODE = 0
        php artisan up
        $upExitCode = $LASTEXITCODE
        if ($upExitCode -ne 0) {
            $message = "Unable to disable maintenance mode; artisan up exited with code $upExitCode."
            Write-UpdateStage -Message $message -Level ERROR
            if ($null -eq $updateError) {
                $updateError = $message
            }
        } else {
            Write-UpdateStage -Message 'Maintenance mode disabled.'
        }
    }
}

if ($null -ne $updateError) {
    throw $updateError
}
