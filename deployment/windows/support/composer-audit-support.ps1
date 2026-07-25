function Test-KaevCmsComposerAuditNetworkFailure {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory)]
        [string] $OutputText
    )

    $networkFailurePatterns = @(
        '(?i)curl error (?:6|7|28|52|56)\b',
        '(?i)resolving timed out',
        '(?i)could not resolve host',
        '(?i)failed to connect to',
        '(?i)connection timed out',
        '(?i)network is unreachable',
        '(?i)temporary failure in name resolution',
        '(?i)getaddrinfo[^\r\n]*(?:failed|error)'
    )

    foreach ($pattern in $networkFailurePatterns) {
        if ($OutputText -match $pattern) {
            return $true
        }
    }

    return $false
}

function Invoke-KaevCmsComposerSecurityAudit {
    [CmdletBinding()]
    param(
        [string] $ComposerCommand = 'composer'
    )

    $composerExecutable = Get-Command $ComposerCommand -ErrorAction Stop
    $previousErrorActionPreference = $ErrorActionPreference
    $nativeErrorPreference = Get-Variable -Name PSNativeCommandUseErrorActionPreference -ErrorAction SilentlyContinue
    $previousNativeErrorPreference = if ($null -ne $nativeErrorPreference) {
        [bool] $nativeErrorPreference.Value
    } else {
        $null
    }
    $composerNetworkVariable = Get-Item Env:COMPOSER_DISABLE_NETWORK -ErrorAction SilentlyContinue
    $hadComposerNetworkSetting = $null -ne $composerNetworkVariable
    $previousComposerNetworkSetting = if ($hadComposerNetworkSetting) {
        [string] $composerNetworkVariable.Value
    } else {
        $null
    }

    try {
        # quality.ps1 temporarily disables Composer networking. A manual security
        # audit must explicitly allow network access, even in the same shell.
        Remove-Item Env:COMPOSER_DISABLE_NETWORK -ErrorAction SilentlyContinue

        # Windows PowerShell may wrap text written by composer.bat to stderr in a
        # NativeCommandError even when Composer exits successfully. The process
        # exit code remains the source of truth for the audit result.
        $ErrorActionPreference = 'Continue'
        if ($null -ne $nativeErrorPreference) {
            $PSNativeCommandUseErrorActionPreference = $false
        }

        $auditOutput = @(& $composerExecutable audit --locked --no-interaction 2>&1)
        $auditExitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
        if ($null -ne $nativeErrorPreference) {
            $PSNativeCommandUseErrorActionPreference = $previousNativeErrorPreference
        }

        if ($hadComposerNetworkSetting) {
            $env:COMPOSER_DISABLE_NETWORK = $previousComposerNetworkSetting
        } else {
            Remove-Item Env:COMPOSER_DISABLE_NETWORK -ErrorAction SilentlyContinue
        }
    }

    $auditLines = @(
        foreach ($entry in $auditOutput) {
            if ($null -eq $entry) {
                continue
            }

            if ($entry -is [System.Management.Automation.ErrorRecord]) {
                [string] $entry.Exception.Message
            } else {
                [string] $entry
            }
        }
    )

    foreach ($line in $auditLines) {
        Write-Host $line
    }

    if ($auditExitCode -eq 0) {
        return $true
    }

    $auditText = $auditLines -join [Environment]::NewLine
    if (Test-KaevCmsComposerAuditNetworkFailure -OutputText $auditText) {
        Write-Warning 'Composer security audit could not reach Packagist because of a network or DNS error. Dependency security has not been verified. Run .\deployment\windows\security-audit.ps1 again when internet access is available.'

        return $false
    }

    throw "Composer security audit failed with exit code $auditExitCode."
}
