$ErrorActionPreference = 'Stop'
$ProjectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..\..'))
Set-Location -LiteralPath $ProjectRoot

. "$PSScriptRoot\..\support\composer-audit-support.ps1"

function Assert-Condition {
    param(
        [Parameter(Mandatory)]
        [bool] $Condition,

        [Parameter(Mandatory)]
        [string] $Message
    )

    if (-not $Condition) {
        throw $Message
    }
}

$dnsTimeout = @'
curl error 28 while downloading https://packagist.org/api/security-advisories/: Resolving timed out after 10003 milliseconds
'@
Assert-Condition -Condition (Test-KaevCmsComposerAuditNetworkFailure -OutputText $dnsTimeout) -Message 'DNS timeout must be classified as a network failure.'

$unreachableHost = 'curl error 6 while downloading https://packagist.org: Could not resolve host: packagist.org'
Assert-Condition -Condition (Test-KaevCmsComposerAuditNetworkFailure -OutputText $unreachableHost) -Message 'Unreachable Packagist host must be classified as a network failure.'

$advisoryResult = 'Found 1 security vulnerability advisory affecting 1 package.'
Assert-Condition -Condition (-not (Test-KaevCmsComposerAuditNetworkFailure -OutputText $advisoryResult)) -Message 'A security advisory must not be classified as a network failure.'

$genericFailure = 'The lock file does not contain a compatible set of packages.'
Assert-Condition -Condition (-not (Test-KaevCmsComposerAuditNetworkFailure -OutputText $genericFailure)) -Message 'A generic Composer failure must remain fatal.'

$successCommand = Join-Path ([System.IO.Path]::GetTempPath()) ('kaevcms-composer-audit-success-' + [guid]::NewGuid().ToString('N') + '.cmd')
$previousComposerNetworkVariable = Get-Item Env:COMPOSER_DISABLE_NETWORK -ErrorAction SilentlyContinue
$hadPreviousComposerNetworkSetting = $null -ne $previousComposerNetworkVariable
$previousComposerNetworkSetting = if ($hadPreviousComposerNetworkSetting) {
    [string] $previousComposerNetworkVariable.Value
} else {
    $null
}

try {
    @'
@echo off
if defined COMPOSER_DISABLE_NETWORK (
    >&2 echo Network disabled, request canceled.
    exit /b 100
)
>&2 echo No security vulnerability advisories found.
exit /b 0
'@ | Set-Content -Path $successCommand -Encoding Ascii

    $env:COMPOSER_DISABLE_NETWORK = '1'
    $successResult = Invoke-KaevCmsComposerSecurityAudit -ComposerCommand $successCommand 6>$null
    Assert-Condition -Condition $successResult -Message 'Successful Composer audit output written to stderr must not become a NativeCommandError failure.'
    Assert-Condition -Condition ($env:COMPOSER_DISABLE_NETWORK -eq '1') -Message 'Composer network policy must be restored after the manual audit.'
} finally {
    Remove-Item -LiteralPath $successCommand -Force -ErrorAction SilentlyContinue

    if ($hadPreviousComposerNetworkSetting) {
        $env:COMPOSER_DISABLE_NETWORK = $previousComposerNetworkSetting
    } else {
        Remove-Item Env:COMPOSER_DISABLE_NETWORK -ErrorAction SilentlyContinue
    }
}

Write-Host 'Composer audit network policy tests completed successfully.' -ForegroundColor Green
