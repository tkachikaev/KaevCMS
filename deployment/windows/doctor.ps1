$ErrorActionPreference = 'Continue'

$scriptFile = $PSCommandPath
if ([string]::IsNullOrWhiteSpace($scriptFile)) {
    $scriptFile = $MyInvocation.MyCommand.Path
}

if ([string]::IsNullOrWhiteSpace($scriptFile)) {
    Write-Host '[FAIL] Unable to determine the location of doctor.ps1.' -ForegroundColor Red
    exit 1
}

$script:projectRoot = [System.IO.Path]::GetFullPath((Join-Path (Split-Path -Parent ([System.IO.Path]::GetFullPath($scriptFile))) '..\..'))
Set-Location -LiteralPath $script:projectRoot

$failed = $false
$script:directoryWriteFailures = @()

function Get-ProjectPath {
    param([Parameter(Mandatory = $true)][string]$RelativePath)

    return [System.IO.Path]::GetFullPath((Join-Path $script:projectRoot $RelativePath))
}

function Test-ItemStatus {
    param(
        [string]$Label,
        [bool]$Ok,
        [string]$Details
    )

    if ($Ok) {
        Write-Host "[OK]   $Label - $Details" -ForegroundColor Green
    } else {
        Write-Host "[FAIL] $Label - $Details" -ForegroundColor Red
        $script:failed = $true
    }
}

function Write-WarningStatus {
    param(
        [string]$Label,
        [string]$Details
    )

    Write-Host "[WARN] $Label - $Details" -ForegroundColor Yellow
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

function ConvertTo-EnvBoolean {
    param([string]$Value)

    return @('1', 'true', 'yes', 'on') -contains $Value.Trim().ToLowerInvariant()
}

function Test-LocalAppUrl {
    param([string]$Url)

    try {
        $uri = [Uri]$Url
        return @('localhost', '127.0.0.1', '::1') -contains $uri.Host.ToLowerInvariant()
    } catch {
        return $false
    }
}

function Remove-WriteTestFile {
    param([string]$Path)

    if ([string]::IsNullOrWhiteSpace($Path) -or -not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        return
    }

    for ($attempt = 1; $attempt -le 3; $attempt++) {
        try {
            Remove-Item -LiteralPath $Path -Force -ErrorAction Stop
            return
        } catch {
            if ($attempt -lt 3) {
                Start-Sleep -Milliseconds 100
            }
        }
    }

    Write-Host "[WARN] Could not remove diagnostic file: $Path" -ForegroundColor Yellow
}

function Test-DirectoriesWritable {
    param([string[]]$Paths)

    $script:directoryWriteFailures = @()

    foreach ($relativePath in $Paths) {
        $absolutePath = Get-ProjectPath -RelativePath $relativePath

        if (-not (Test-Path -LiteralPath $absolutePath -PathType Container)) {
            $script:directoryWriteFailures += "$relativePath (directory is missing: $absolutePath)"
            continue
        }

        $writeTestPath = Join-Path $absolutePath ('.kaevcms-write-test-' + [Guid]::NewGuid().ToString('N') + '.tmp')

        try {
            $utf8 = New-Object System.Text.UTF8Encoding($false)
            [System.IO.File]::WriteAllText($writeTestPath, 'ok', $utf8)

            if (-not (Test-Path -LiteralPath $writeTestPath -PathType Leaf)) {
                throw 'The diagnostic file was not created.'
            }
        } catch {
            $script:directoryWriteFailures += "$relativePath ($($_.Exception.Message))"
        } finally {
            Remove-WriteTestFile -Path $writeTestPath
        }
    }

    return $script:directoryWriteFailures.Count -eq 0
}

$versionPath = Get-ProjectPath -RelativePath 'VERSION'
$versionFilePresent = Test-Path -LiteralPath $versionPath -PathType Leaf
$cmsVersion = if ($versionFilePresent) { (Get-Content -LiteralPath $versionPath -Raw).Trim() } else { 'missing' }
$versionFormatOk = $versionFilePresent -and $cmsVersion -match '^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$'

Write-Host "KaevCMS $cmsVersion environment check"
Write-Host "Project: $script:projectRoot"
Write-Host ''

Test-ItemStatus 'VERSION file' $versionFormatOk $(if (-not $versionFilePresent) { 'missing' } elseif (-not $versionFormatOk) { "invalid value: $cmsVersion" } else { $cmsVersion })

$envPath = Get-ProjectPath -RelativePath '.env'
$dbConnection = (Get-EnvValue -Path $envPath -Name 'DB_CONNECTION' -Default 'sqlite').ToLowerInvariant()
$appEnvironment = (Get-EnvValue -Path $envPath -Name 'APP_ENV' -Default 'production').ToLowerInvariant()
$appDebug = ConvertTo-EnvBoolean (Get-EnvValue -Path $envPath -Name 'APP_DEBUG' -Default 'false')
$appUrl = Get-EnvValue -Path $envPath -Name 'APP_URL' -Default 'http://127.0.0.1:8000'
$appForceHttps = ConvertTo-EnvBoolean (Get-EnvValue -Path $envPath -Name 'APP_FORCE_HTTPS' -Default 'false')
$sessionSecureCookie = ConvertTo-EnvBoolean (Get-EnvValue -Path $envPath -Name 'SESSION_SECURE_COOKIE' -Default 'false')
$logLevel = (Get-EnvValue -Path $envPath -Name 'LOG_LEVEL' -Default 'debug').ToLowerInvariant()
$hashDriver = (Get-EnvValue -Path $envPath -Name 'HASH_DRIVER' -Default 'auto').ToLowerInvariant()
$hashVerify = ConvertTo-EnvBoolean (Get-EnvValue -Path $envPath -Name 'HASH_VERIFY' -Default 'true')
$trustedProxies = Get-EnvValue -Path $envPath -Name 'TRUSTED_PROXIES' -Default ''
$dbBusyTimeout = Get-EnvValue -Path $envPath -Name 'DB_BUSY_TIMEOUT' -Default '5000'

$phpCommand = Get-Command php -ErrorAction SilentlyContinue
Test-ItemStatus 'PHP command' ($null -ne $phpCommand) $(if ($phpCommand) { $phpCommand.Source } else { 'not found in PATH' })

if ($phpCommand) {
    $phpVersionText = (& php -r "echo PHP_VERSION;").Trim()
    $versionOk = $false
    try { $versionOk = ([Version]$phpVersionText -ge [Version]'8.3.0') } catch {}
    Test-ItemStatus 'PHP version' $versionOk $phpVersionText

    $requiredExtensions = @('ctype', 'dom', 'fileinfo', 'mbstring', 'openssl', 'pdo', 'pdo_mysql', 'tokenizer', 'xml')

    if ($dbConnection -eq 'sqlite') {
        $requiredExtensions += 'pdo_sqlite'
    } elseif ($dbConnection -eq 'mysql') {
        $requiredExtensions += 'pdo_mysql'
    } else {
        Write-WarningStatus 'CMS database driver' "DB_CONNECTION=$dbConnection is not covered by the built-in SQLite/MySQL checks"
    }


    $requiredExtensions = @($requiredExtensions | Select-Object -Unique)
    $loadedExtensions = & php -r "echo implode(PHP_EOL, get_loaded_extensions());"
    foreach ($extension in $requiredExtensions) {
        Test-ItemStatus "PHP extension $extension" ($loadedExtensions -contains $extension) $(if ($loadedExtensions -contains $extension) { 'loaded' } else { 'missing' })
    }

    $passwordAlgorithms = & php -r "echo implode(PHP_EOL, password_algos());"
    $algorithmCommandOk = $LASTEXITCODE -eq 0
    $effectiveHashDriver = $hashDriver

    if ($hashDriver -eq 'auto') {
        if ($algorithmCommandOk -and ($passwordAlgorithms -contains 'argon2id')) {
            $effectiveHashDriver = 'argon2id'
        } elseif ($algorithmCommandOk -and ($passwordAlgorithms -contains '2y')) {
            $effectiveHashDriver = 'bcrypt'
        } else {
            $effectiveHashDriver = ''
        }
    }

    $hashAlgorithm = switch ($effectiveHashDriver) {
        'bcrypt' { '2y' }
        'argon' { 'argon2i' }
        'argon2id' { 'argon2id' }
        default { $null }
    }

    if ([string]::IsNullOrWhiteSpace($hashAlgorithm)) {
        Test-ItemStatus 'Password hashing' $false "unsupported HASH_DRIVER=$hashDriver; use auto, bcrypt, argon or argon2id"
    } else {
        $hashSupported = $algorithmCommandOk -and ($passwordAlgorithms -contains $hashAlgorithm)
        if ($hashDriver -eq 'auto' -and $effectiveHashDriver -eq 'bcrypt' -and $hashSupported) {
            Write-WarningStatus 'Password hashing' 'auto selected bcrypt because this PHP executable does not provide argon2id; check php --ini and whether the sodium extension is loaded'
        } else {
            $hashDetails = if ($hashSupported) {
                if ($hashDriver -eq 'auto') { "auto selected $effectiveHashDriver" } else { "$effectiveHashDriver is supported" }
            } else {
                "$effectiveHashDriver is unavailable in this PHP executable"
            }
            Test-ItemStatus 'Password hashing' $hashSupported $hashDetails
        }
    }

    if (-not $hashVerify) {
        Write-WarningStatus 'Hash algorithm verification' 'HASH_VERIFY=false allows mixed bcrypt/Argon hashes during migration; restore HASH_VERIFY=true after legacy hashes have been upgraded or reset'
    } else {
        Test-ItemStatus 'Hash algorithm verification' $true 'strict algorithm verification is enabled'
    }
}

$composerCommand = Get-Command composer -ErrorAction SilentlyContinue
Test-ItemStatus 'Composer command' ($null -ne $composerCommand) $(if ($composerCommand) { $composerCommand.Source } else { 'not found in PATH' })

$autoloadPath = Get-ProjectPath -RelativePath 'vendor\autoload.php'
$bootstrapCachePath = Get-ProjectPath -RelativePath 'bootstrap\cache'
$storageViewsPath = Get-ProjectPath -RelativePath 'storage\framework\views'
$reservedAdminPath = Get-ProjectPath -RelativePath 'public\admin'

Test-ItemStatus '.env file' (Test-Path -LiteralPath $envPath -PathType Leaf) $(if (Test-Path -LiteralPath $envPath -PathType Leaf) { 'present' } else { 'missing' })

$appKeyConfigured = $false
if (Test-Path -LiteralPath $envPath -PathType Leaf) {
    $appKeyLine = Get-Content -LiteralPath $envPath | Where-Object { $_ -match '^\s*APP_KEY\s*=' } | Select-Object -First 1
    if ($null -ne $appKeyLine) {
        $appKeyValue = (($appKeyLine -split '=', 2)[1]).Trim().Trim([char]34).Trim([char]39)
        $appKeyConfigured = -not [string]::IsNullOrWhiteSpace($appKeyValue)
    }
}
Test-ItemStatus 'APP_KEY' $appKeyConfigured $(if ($appKeyConfigured) { 'configured; keep a secure backup because encrypted 2FA secrets depend on it' } else { 'missing; run php artisan key:generate before enabling 2FA' })

if (Test-Path -LiteralPath $envPath -PathType Leaf) {
    if ($appEnvironment -ne 'production') {
        Write-WarningStatus 'APP_ENV' "$appEnvironment; use production on a public website"
    }

    $isLocalUrl = Test-LocalAppUrl -Url $appUrl
    $appUri = $null
    try { $appUri = [Uri]$appUrl } catch { Write-WarningStatus 'APP_URL' "invalid URL: $appUrl" }

    if ($null -ne $appUri -and $appUri.Scheme -eq 'http' -and -not $isLocalUrl) {
        Write-WarningStatus 'APP_URL' 'public HTTP URL; configure HTTPS before production use'
    }

    if ($appDebug) {
        Write-WarningStatus 'APP_DEBUG' 'true; disable it before production use'
    }

    if ($appEnvironment -eq 'production') {
        if (-not $appForceHttps) { Write-WarningStatus 'APP_FORCE_HTTPS' 'false in production; HTTPS is not forced by the CMS' }
        if ($logLevel -eq 'debug') { Write-WarningStatus 'LOG_LEVEL' 'debug in production may create excessive diagnostic output' }
        if ($dbConnection -eq 'sqlite') { Write-WarningStatus 'CMS database' 'SQLite is intended for local development and testing; use MySQL or MariaDB for a public site' }
    }

    if ([string]::IsNullOrWhiteSpace($trustedProxies)) {
        Test-ItemStatus 'Trusted proxies' $true 'disabled; correct when requests reach the web server directly'
    } elseif ($trustedProxies.Trim() -eq '*') {
        Write-WarningStatus 'Trusted proxies' 'TRUSTED_PROXIES=* trusts forwarded headers from every proxy; use only when direct access to the web server is blocked'
    } else {
        $trustedProxyCount = @($trustedProxies -split '[,;\s]+' | Where-Object { -not [string]::IsNullOrWhiteSpace($_) }).Count
        Test-ItemStatus 'Trusted proxies' ($trustedProxyCount -gt 0) "$trustedProxyCount configured address(es); verify the IP/CIDR values in System information"
    }

    if ($null -ne $appUri -and $appUri.Scheme -eq 'https' -and -not $sessionSecureCookie) {
        Write-WarningStatus 'SESSION_SECURE_COOKIE' 'false while APP_URL uses HTTPS'
    }
}

Test-ItemStatus 'Composer dependencies' (Test-Path -LiteralPath $autoloadPath -PathType Leaf) $(if (Test-Path -LiteralPath $autoloadPath -PathType Leaf) { 'installed' } else { 'run .\deployment\windows\setup.ps1' })
if ($dbConnection -eq 'sqlite') {
    $busyTimeoutValue = 0
    $busyTimeoutOk = [int]::TryParse($dbBusyTimeout, [ref]$busyTimeoutValue) -and $busyTimeoutValue -ge 0
    Test-ItemStatus 'SQLite lock wait' $busyTimeoutOk $(if ($busyTimeoutOk) { "$busyTimeoutValue ms" } else { "invalid DB_BUSY_TIMEOUT=$dbBusyTimeout; use a non-negative integer such as 5000" })

    $configuredDatabasePath = Get-EnvValue -Path $envPath -Name 'DB_DATABASE' -Default 'database/database.sqlite'
    $databasePath = if ([System.IO.Path]::IsPathRooted($configuredDatabasePath)) {
        [System.IO.Path]::GetFullPath($configuredDatabasePath)
    } else {
        Get-ProjectPath -RelativePath $configuredDatabasePath
    }

    Test-ItemStatus 'SQLite database' (Test-Path -LiteralPath $databasePath -PathType Leaf) $(if (Test-Path -LiteralPath $databasePath -PathType Leaf) { 'present' } else { "missing: $configuredDatabasePath" })
} else {
    Write-Host "[SKIP] SQLite database - DB_CONNECTION=$dbConnection" -ForegroundColor DarkGray
}
Test-ItemStatus 'Bootstrap cache directory' (Test-Path -LiteralPath $bootstrapCachePath -PathType Container) $(if (Test-Path -LiteralPath $bootstrapCachePath -PathType Container) { 'present' } else { 'missing' })
Test-ItemStatus 'Storage views directory' (Test-Path -LiteralPath $storageViewsPath -PathType Container) $(if (Test-Path -LiteralPath $storageViewsPath -PathType Container) { 'present' } else { 'missing' })
Test-ItemStatus 'Reserved public/admin path' (-not (Test-Path -LiteralPath $reservedAdminPath)) $(if (Test-Path -LiteralPath $reservedAdminPath) { 'conflicts with the /admin Laravel route; move assets to public\assets\admin' } else { 'not present' })

$newsUploadPaths = @(
    'public\uploads\news\covers',
    'public\uploads\news\content'
)
$newsUploadWritable = Test-DirectoriesWritable -Paths $newsUploadPaths
$newsUploadFailures = @($script:directoryWriteFailures)
$missingNewsUploadPaths = @($newsUploadPaths | Where-Object { -not (Test-Path -LiteralPath (Get-ProjectPath -RelativePath $_) -PathType Container) })
$newsUploadDetails = if ($missingNewsUploadPaths.Count -gt 0) {
    'missing: ' + ($missingNewsUploadPaths -join ', ') + '; run .\deployment\windows\setup.ps1 or .\deployment\windows\update.ps1'
} elseif ($newsUploadWritable) {
    'cover and content directories are writable'
} else {
    'write failed: ' + ($newsUploadFailures -join '; ')
}
Test-ItemStatus 'News upload directories' $newsUploadWritable $newsUploadDetails

$pageUploadPaths = @(
    'public\uploads\pages\content'
)
$pageUploadWritable = Test-DirectoriesWritable -Paths $pageUploadPaths
$pageUploadFailures = @($script:directoryWriteFailures)
$missingPageUploadPaths = @($pageUploadPaths | Where-Object { -not (Test-Path -LiteralPath (Get-ProjectPath -RelativePath $_) -PathType Container) })
$pageUploadDetails = if ($missingPageUploadPaths.Count -gt 0) {
    'missing: ' + ($missingPageUploadPaths -join ', ') + '; run .\deployment\windows\setup.ps1 or .\deployment\windows\update.ps1'
} elseif ($pageUploadWritable) {
    'content directory is writable'
} else {
    'write failed: ' + ($pageUploadFailures -join '; ')
}
Test-ItemStatus 'Page upload directory' $pageUploadWritable $pageUploadDetails

$settingsUploadPaths = @(
    'public\uploads\settings\logo',
    'public\uploads\settings\favicon'
)
$settingsUploadWritable = Test-DirectoriesWritable -Paths $settingsUploadPaths
$settingsUploadFailures = @($script:directoryWriteFailures)
$missingSettingsUploadPaths = @($settingsUploadPaths | Where-Object { -not (Test-Path -LiteralPath (Get-ProjectPath -RelativePath $_) -PathType Container) })
$settingsUploadDetails = if ($missingSettingsUploadPaths.Count -gt 0) {
    'missing: ' + ($missingSettingsUploadPaths -join ', ') + '; run .\deployment\windows\setup.ps1 or .\deployment\windows\update.ps1'
} elseif ($settingsUploadWritable) {
    'logo and favicon directories are writable'
} else {
    'write failed: ' + ($settingsUploadFailures -join '; ')
}
Test-ItemStatus 'Settings upload directories' $settingsUploadWritable $settingsUploadDetails

$accountAvatarPaths = @(
    'public\uploads\account-avatars'
)
$accountAvatarWritable = Test-DirectoriesWritable -Paths $accountAvatarPaths
$accountAvatarFailures = @($script:directoryWriteFailures)
$missingAccountAvatarPaths = @($accountAvatarPaths | Where-Object { -not (Test-Path -LiteralPath (Get-ProjectPath -RelativePath $_) -PathType Container) })
$accountAvatarDetails = if ($missingAccountAvatarPaths.Count -gt 0) {
    'missing: ' + ($missingAccountAvatarPaths -join ', ') + '; run .\deployment\windows\setup.ps1 or .\deployment\windows\update.ps1'
} elseif ($accountAvatarWritable) {
    'avatar directory is writable'
} else {
    'write failed: ' + ($accountAvatarFailures -join '; ')
}
Test-ItemStatus 'Account avatar directory' $accountAvatarWritable $accountAvatarDetails

$builtInLanguageFiles = @(
    'lang\ru\language.php',
    'lang\ru.json',
    'lang\en\language.php',
    'lang\en.json'
)
$missingLanguageFiles = @($builtInLanguageFiles | Where-Object {
    -not (Test-Path -LiteralPath (Get-ProjectPath -RelativePath $_) -PathType Leaf)
})
Test-ItemStatus 'Built-in language files' ($missingLanguageFiles.Count -eq 0) $(
    if ($missingLanguageFiles.Count -eq 0) { 'Russian and English packs are present' }
    else { 'missing: ' + ($missingLanguageFiles -join ', ') }
)

$languageJsonOk = $true
$languageJsonDetails = @()
$languageKeys = @{}
foreach ($locale in @('ru', 'en')) {
    $jsonPath = Get-ProjectPath -RelativePath ("lang\$locale.json")
    if (-not (Test-Path -LiteralPath $jsonPath -PathType Leaf)) {
        $languageJsonOk = $false
        continue
    }

    try {
        $jsonObject = Get-Content -LiteralPath $jsonPath -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop
        $keys = @($jsonObject.PSObject.Properties.Name)
        $languageKeys[$locale] = $keys
        $languageJsonDetails += "$locale=$($keys.Count) keys"
    } catch {
        $languageJsonOk = $false
        $languageJsonDetails += "$locale invalid JSON: $($_.Exception.Message)"
    }
}

if ($languageJsonOk -and $languageKeys.ContainsKey('ru') -and $languageKeys.ContainsKey('en')) {
    $missingInRussian = @($languageKeys['en'] | Where-Object { $languageKeys['ru'] -notcontains $_ })
    $missingInEnglish = @($languageKeys['ru'] | Where-Object { $languageKeys['en'] -notcontains $_ })
    if ($missingInRussian.Count -gt 0 -or $missingInEnglish.Count -gt 0) {
        $languageJsonOk = $false
        $languageJsonDetails += "missing ru=$($missingInRussian.Count), missing en=$($missingInEnglish.Count)"
    }
}
Test-ItemStatus 'Built-in language translations' $languageJsonOk $(
    if ($languageJsonDetails.Count -gt 0) { $languageJsonDetails -join '; ' }
    else { 'language JSON files are unavailable' }
)

if ($phpCommand -and $missingLanguageFiles.Count -eq 0) {
    foreach ($locale in @('ru', 'en')) {
        $metadataPath = Get-ProjectPath -RelativePath ("lang\$locale\language.php")
        & php -l $metadataPath *> $null
        Test-ItemStatus "Language metadata $locale" ($LASTEXITCODE -eq 0) $(if ($LASTEXITCODE -eq 0) { 'valid PHP' } else { 'PHP syntax error' })
    }
}

if ((Test-Path -LiteralPath $autoloadPath -PathType Leaf) -and (Test-Path -LiteralPath $envPath -PathType Leaf)) {
    $installedVersionOutput = (& php artisan kaevcms:release-version --no-ansi 2>&1 | Out-String).Trim()
    $installedVersionOk = $LASTEXITCODE -eq 0 -and $installedVersionOutput -eq $cmsVersion
    Test-ItemStatus 'Installed release version' $installedVersionOk $(
        if ($installedVersionOk) { $installedVersionOutput }
        elseif ([string]::IsNullOrWhiteSpace($installedVersionOutput)) { 'not recorded; rerun setup or the current apply script' }
        else { "expected $cmsVersion, reported: $installedVersionOutput" }
    )

    $encryptionHealthOutput = (& php artisan kaevcms:encryption-health --no-ansi 2>&1 | Out-String).Trim()
    $encryptionHealthOk = $LASTEXITCODE -eq 0
    Test-ItemStatus 'Encrypted secrets' $encryptionHealthOk $(
        if ([string]::IsNullOrWhiteSpace($encryptionHealthOutput)) { 'encryption health check returned no details' }
        else { $encryptionHealthOutput -replace "`r?`n", '; ' }
    )

    $scheduleOutput = (& php artisan schedule:list --no-ansi 2>&1 | Out-String)
    $scheduleCommandOk = $LASTEXITCODE -eq 0
    Test-ItemStatus 'Laravel schedule list' $scheduleCommandOk $(if ($scheduleCommandOk) { 'loaded successfully' } else { 'php artisan schedule:list failed' })

    if ($scheduleCommandOk) {
        Test-ItemStatus 'Scheduler heartbeat task' ($scheduleOutput -match 'kaevcms:scheduler-heartbeat') $(if ($scheduleOutput -match 'kaevcms:scheduler-heartbeat') { 'registered every minute' } else { 'missing from Laravel schedule' })
        Test-ItemStatus 'Database queue drain task' ($scheduleOutput -match 'kaevcms:queue-drain') $(if ($scheduleOutput -match 'kaevcms:queue-drain') { 'registered every minute' } else { 'missing from Laravel schedule' })
        Test-ItemStatus 'Queue retention cleanup task' ($scheduleOutput -match 'kaevcms:queue-clean') $(if ($scheduleOutput -match 'kaevcms:queue-clean') { 'registered daily' } else { 'missing from Laravel schedule' })
    }

    Write-Host ''
    Write-Host 'Laravel check:'
    php artisan about --only=environment,cache,drivers
    if ($LASTEXITCODE -ne 0) {
        $failed = $true
    }
}

Write-Host ''
if ($failed) {
    Write-Host 'One or more checks failed.' -ForegroundColor Red
    exit 1
}

Write-Host 'All checks passed.' -ForegroundColor Green
exit 0
