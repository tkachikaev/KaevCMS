param(
    [switch]$SkipTests
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
Set-Location -LiteralPath $ProjectRoot

function Invoke-Checked {
    param(
        [Parameter(Mandatory = $true)][string]$Label,
        [Parameter(Mandatory = $true)][scriptblock]$Command
    )

    Write-Host "==> $Label"
    & $Command
    if ($LASTEXITCODE -ne 0) {
        throw "$Label failed with exit code $LASTEXITCODE."
    }
}

function Write-Utf8NoBom {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$Content
    )

    $utf8 = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText((Resolve-Path $Path), $Content, $utf8)
}

function Set-EnvValue {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$Name,
        [Parameter(Mandatory = $true)][string]$Value
    )

    $content = [System.IO.File]::ReadAllText((Resolve-Path $Path))
    $pattern = '(?m)^\s*' + [regex]::Escape($Name) + '\s*=.*$'
    $replacement = "$Name=$Value"

    if ([regex]::IsMatch($content, $pattern)) {
        $content = [regex]::Replace($content, $pattern, $replacement)
    } else {
        $content = $content.TrimEnd([char[]]"`r`n") + [Environment]::NewLine + $replacement + [Environment]::NewLine
    }

    Write-Utf8NoBom -Path $Path -Content $content
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

    $result = ([string]$output).Trim()
    return $result -eq '1'
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

function Ensure-Directory {
    param([Parameter(Mandatory = $true)][string]$Path)
    New-Item -Path $Path -ItemType Directory -Force | Out-Null
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

if (-not (Test-Path 'VERSION')) {
    throw 'VERSION is missing. Re-extract the complete KaevCMS release.'
}

$cmsVersion = (Get-Content 'VERSION' -Raw).Trim()
if ($cmsVersion -notmatch '^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$') {
    throw "VERSION contains an invalid release number: $cmsVersion"
}

Write-Host "KaevCMS $cmsVersion setup"
Write-Host "Project: $ProjectRoot"
Write-Host ''

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    throw 'PHP was not found in PATH.'
}

if (-not (Get-Command composer -ErrorAction SilentlyContinue)) {
    throw 'Composer was not found in PATH.'
}

if (-not (Test-Path 'composer.lock')) {
    throw 'composer.lock is missing. Re-extract the complete KaevCMS release. Setup will not install unpinned dependency versions.'
}

$phpVersionText = (& php -r "echo PHP_VERSION;").Trim()
if ($LASTEXITCODE -ne 0) {
    throw 'Unable to read PHP version.'
}

try {
    $phpVersion = [Version]$phpVersionText
} catch {
    throw "Unable to parse PHP version: $phpVersionText"
}

if ($phpVersion -lt [Version]'8.3.0') {
    throw "PHP 8.3 or newer is required. Installed: $phpVersionText"
}

$environmentSource = if (Test-Path '.env') { '.env' } else { '.env.example' }
$dbConnection = (Get-EnvValue -Path $environmentSource -Name 'DB_CONNECTION' -Default 'sqlite').ToLowerInvariant()

$requiredExtensions = @(
    'ctype',
    'dom',
    'fileinfo',
    'mbstring',
    'openssl',
    'pdo',
    'pdo_mysql',
    'tokenizer',
    'xml'
)

if ($dbConnection -eq 'sqlite') {
    $requiredExtensions += 'pdo_sqlite'
} elseif ($dbConnection -eq 'mysql') {
    $requiredExtensions += 'pdo_mysql'
} else {
    throw "Unsupported DB_CONNECTION for setup.ps1: $dbConnection. Use sqlite or mysql."
}


$requiredExtensions = @($requiredExtensions | Select-Object -Unique)
$loadedExtensions = & php -r "echo implode(PHP_EOL, get_loaded_extensions());"
if ($LASTEXITCODE -ne 0) {
    throw 'Unable to read loaded PHP extensions.'
}

foreach ($extension in $requiredExtensions) {
    if ($loadedExtensions -notcontains $extension) {
        throw "Required PHP extension is missing: $extension"
    }
}

$directories = @(
    'bootstrap\cache',
    'storage\app\private',
    'storage\app\public',
    'storage\framework\cache\data',
    'storage\framework\sessions',
    'storage\framework\views',
    'storage\logs',
    'database',
    'public\uploads\news\covers',
    'public\uploads\news\content',
    'public\uploads\pages\content',
    'public\uploads\settings\logo',
    'public\uploads\settings\favicon',
    'public\uploads\account-avatars',
    'public\uploads\game-assets\items\common',
    'public\uploads\game-assets\items\servers',
    'public\uploads\game-assets\characters\common',
    'public\uploads\game-assets\characters\servers'
)

foreach ($directory in $directories) {
    Ensure-Directory $directory
}

if (-not (Test-Path '.env')) {
    if (-not (Test-Path '.env.example')) {
        throw '.env.example was not found.'
    }

    Copy-Item '.env.example' '.env'
    Write-Host 'Created .env from .env.example.'
}

$envContent = [System.IO.File]::ReadAllText((Resolve-Path '.env'))
$fixedEnvContent = [regex]::Replace(
    $envContent,
    '(?m)^GAME_SERVER_NAME=([^"\r\n]*\s+[^\r\n]*)$',
    'GAME_SERVER_NAME="$1"'
)

$legacyBrandDefaults = @(
    @{ Pattern = '(?m)^APP_NAME=(?:"L2Forge CMS"|L2Forge CMS|"?L2CMS"?)[ \t]*\r?$'; Replacement = 'APP_NAME="KaevCMS"' },
    @{ Pattern = '(?m)^SITE_NAME=(?:"L2Forge CMS"|L2Forge CMS)[ \t]*\r?$'; Replacement = 'SITE_NAME="KaevCMS"' },
    @{ Pattern = '(?m)^SITE_NAME_RU=(?:"L2Forge CMS"|L2Forge CMS)[ \t]*\r?$'; Replacement = 'SITE_NAME_RU="KaevCMS"' },
    @{ Pattern = '(?m)^SITE_NAME_EN=(?:"L2Forge CMS"|L2Forge CMS)[ \t]*\r?$'; Replacement = 'SITE_NAME_EN="KaevCMS"' },
    @{ Pattern = '(?m)^SITE_FOOTER_TEXT=(?:"© 2026 L2Forge-CMS"|© 2026 L2Forge-CMS|"© 2026 L2Forge CMS"|© 2026 L2Forge CMS)[ \t]*\r?$'; Replacement = 'SITE_FOOTER_TEXT="© 2026 KaevCMS"' },
    @{ Pattern = '(?m)^SITE_FOOTER_TEXT_RU=(?:"© 2026 L2Forge-CMS"|© 2026 L2Forge-CMS|"© 2026 L2Forge CMS"|© 2026 L2Forge CMS)[ \t]*\r?$'; Replacement = 'SITE_FOOTER_TEXT_RU="© 2026 KaevCMS"' },
    @{ Pattern = '(?m)^SITE_FOOTER_TEXT_EN=(?:"© 2026 L2Forge-CMS"|© 2026 L2Forge-CMS|"© 2026 L2Forge CMS"|© 2026 L2Forge CMS)[ \t]*\r?$'; Replacement = 'SITE_FOOTER_TEXT_EN="© 2026 KaevCMS"' },
    @{ Pattern = '(?m)^MAIL_FROM_NAME=(?:"L2Forge CMS"|L2Forge CMS)[ \t]*\r?$'; Replacement = 'MAIL_FROM_NAME="KaevCMS"' }
)

foreach ($legacyBrandDefault in $legacyBrandDefaults) {
    $fixedEnvContent = [regex]::Replace(
        $fixedEnvContent,
        $legacyBrandDefault.Pattern,
        $legacyBrandDefault.Replacement
    )
}

if ($fixedEnvContent -ne $envContent) {
    Write-Utf8NoBom -Path '.env' -Content $fixedEnvContent
    Write-Host 'Updated legacy values in .env.'
}

$hashDriver = (Get-EnvValue -Path '.env' -Name 'HASH_DRIVER' -Default 'auto').ToLowerInvariant()
$knownHashDrivers = @('auto', 'bcrypt', 'argon', 'argon2id')
if ($knownHashDrivers -notcontains $hashDriver) {
    throw "Unsupported HASH_DRIVER: $hashDriver. Use auto, bcrypt, argon or argon2id."
}

$effectiveHashDriver = Resolve-PasswordHashDriver -Driver $hashDriver
if ([string]::IsNullOrWhiteSpace($effectiveHashDriver)) {
    if ($hashDriver -eq 'argon' -or $hashDriver -eq 'argon2id') {
        throw "HASH_DRIVER=$hashDriver is not supported by this PHP executable. Argon2 support is compiled into PHP or supplied by its sodium implementation; it is not a Composer package. Check: php -r `"print_r(password_algos());`", php --ini, and php -m | findstr /I sodium."
    }

    throw "No supported password hashing algorithm is available in this PHP executable."
}

if ($hashDriver -eq 'auto' -and $effectiveHashDriver -eq 'bcrypt') {
    Write-Host '[WARN] Argon2id is unavailable in this PHP executable; HASH_DRIVER=auto selected bcrypt. Use a PHP build whose password_algos() contains argon2id to use Argon2id.' -ForegroundColor Yellow
}

Write-Host "Password hashing: $effectiveHashDriver (requested: $hashDriver)"

$dbConnection = (Get-EnvValue -Path '.env' -Name 'DB_CONNECTION' -Default 'sqlite').ToLowerInvariant()
if ($dbConnection -eq 'sqlite') {
    $configuredDatabasePath = Get-EnvValue -Path '.env' -Name 'DB_DATABASE' -Default 'database/database.sqlite'
    $sqlitePath = if ([System.IO.Path]::IsPathRooted($configuredDatabasePath)) {
        [System.IO.Path]::GetFullPath($configuredDatabasePath)
    } else {
        [System.IO.Path]::GetFullPath((Join-Path $ProjectRoot $configuredDatabasePath))
    }

    Ensure-Directory ([System.IO.Path]::GetDirectoryName($sqlitePath))
    if (-not (Test-Path -LiteralPath $sqlitePath -PathType Leaf)) {
        New-Item -Path $sqlitePath -ItemType File -Force | Out-Null
        Write-Host "Created SQLite database file: $configuredDatabasePath"
    }
}

Invoke-Checked 'Composer validation' { composer validate --no-check-publish }
Invoke-Checked 'Installing PHP dependencies' { composer install --no-interaction --prefer-dist }

$envAfterInstall = [System.IO.File]::ReadAllText((Resolve-Path '.env'))
$appKeyMatch = [regex]::Match($envAfterInstall, '(?m)^APP_KEY=(.*)$')
if (-not $appKeyMatch.Success -or [string]::IsNullOrWhiteSpace($appKeyMatch.Groups[1].Value)) {
    Invoke-Checked 'Generating application key' { php artisan key:generate --force }
} else {
    Write-Host '==> Application key already exists; keeping it unchanged.'
}

Invoke-Checked 'Clearing Laravel caches' { php artisan optimize:clear }
Invoke-Checked 'Running database migrations and seeders' { php artisan migrate --seed --force }
Invoke-Checked 'Refreshing server monitoring snapshot' { php artisan kaevcms:servers-monitor --force }
Invoke-Checked 'Application smoke check' { php artisan about }

if (-not $SkipTests) {
    Invoke-Checked 'Running automated tests' { php artisan test }
}

Invoke-Checked 'Recording installed release version' { php artisan kaevcms:release-version --mark=$cmsVersion }

$installerLockPath = Join-Path $ProjectRoot 'storage\app\installed.lock'
New-Item -Path (Split-Path -Parent $installerLockPath) -ItemType Directory -Force | Out-Null
[ordered]@{
    version = $cmsVersion
    installed_at = (Get-Date).ToUniversalTime().ToString('o')
    source = 'windows-setup'
} | ConvertTo-Json | Set-Content -LiteralPath $installerLockPath -Encoding UTF8

Write-Host ''
Write-Host 'Setup completed successfully.' -ForegroundColor Green
Write-Host 'Create the first administrator with: php artisan kaevcms:admin-create'
Write-Host 'Start the local site with: .\deployment\windows\serve.ps1'
Write-Host 'Admin panel: http://127.0.0.1:8000/admin'
Write-Host 'For browser tests, run once: .\deployment\windows\browser-setup.ps1' -ForegroundColor DarkGray
