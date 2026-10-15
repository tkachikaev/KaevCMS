#requires -Version 5.1
[CmdletBinding()]
param(
    [ValidatePattern('^[A-Za-z0-9_][A-Za-z0-9_.@-]*$')][string] $SshHost = 'kaevcms-test',
    [ValidatePattern('^/[A-Za-z0-9_/-]+$')][string] $RemoteRoot = '/var/www/kaevcms',
    [string] $SourceRoot = '',
    [ValidateRange(1, 60)][int] $PollSeconds = 2,
    [switch] $Once
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
if ([string]::IsNullOrWhiteSpace($SourceRoot)) { $SourceRoot = $ProjectRoot }
$SourceRoot = [System.IO.Path]::GetFullPath($SourceRoot).TrimEnd('\', '/')
if (-not (Test-Path -LiteralPath (Join-Path $SourceRoot 'artisan') -PathType Leaf)) { throw 'SourceRoot must be a KaevCMS source checkout.' }
if ($RemoteRoot -eq '/' -or $RemoteRoot -match '(^|/)\.\.?(/|$)' -or $RemoteRoot.Contains('//')) { throw 'RemoteRoot must be a dedicated KaevCMS project directory.' }
foreach ($command in @('ssh', 'scp', 'tar')) { if (-not (Get-Command $command -ErrorAction SilentlyContinue)) { throw "$command is required." } }
. (Join-Path $PSScriptRoot 'support\live-dev-support.ps1')
$applyScript = Join-Path $ProjectRoot 'deployment\development\live-dev-apply.sh'
$sshOptions = @('-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=10')
$encoding = [System.Text.UTF8Encoding]::new($false)
$previous = @{}
$temporaryRoot = Join-Path $ProjectRoot 'storage\framework\testing\live-dev'
New-Item -ItemType Directory -Path $temporaryRoot -Force | Out-Null

function Send-LiveDevBatch {
    param([object[]] $Changed, [string[]] $Deleted)

    $batch = Join-Path $temporaryRoot ([guid]::NewGuid().ToString('N'))
    $payload = Join-Path $batch 'payload'
    $remoteStage = $null
    $applied = $false
    New-Item -ItemType Directory -Path $payload -Force | Out-Null
    try {
        foreach ($file in $Changed) {
            $destination = Join-Path $payload $file.Relative
            New-Item -ItemType Directory -Path (Split-Path -Parent $destination) -Force | Out-Null
            Copy-Item -LiteralPath $file.FullName -Destination $destination
        }
        $fileList = @($Changed | ForEach-Object { $_.Relative })
        [System.IO.File]::WriteAllText((Join-Path $batch 'files.txt'), (($fileList -join "`n") + "`n"), $encoding)
        [System.IO.File]::WriteAllText((Join-Path $batch 'delete.txt'), (($Deleted -join "`n") + "`n"), $encoding)
        Copy-Item -LiteralPath $applyScript -Destination (Join-Path $batch 'apply.sh')
        $archive = Join-Path $batch 'source.tar.gz'
        & tar -czf $archive -C $batch payload files.txt delete.txt apply.sh
        if ($LASTEXITCODE -ne 0) { throw 'Unable to create the live-dev source batch.' }
        $stageOutput = @(& ssh @sshOptions $SshHost 'umask 077; mktemp -d /tmp/kaevcms-live-dev.XXXXXXXX')
        if ($LASTEXITCODE -ne 0 -or $stageOutput.Count -ne 1 -or $stageOutput[0] -notmatch '^/tmp/kaevcms-live-dev\.[A-Za-z0-9]+$') { throw 'Unable to create a private remote staging directory.' }
        $remoteStage = $stageOutput[0]
        & scp @sshOptions -q $archive "${SshHost}:${remoteStage}/source.tar.gz"
        if ($LASTEXITCODE -ne 0) { throw 'Live-dev upload failed.' }
        & ssh @sshOptions $SshHost "set -eu; tar -xzf '$remoteStage/source.tar.gz' -C '$remoteStage'; bash '$remoteStage/apply.sh' '$RemoteRoot' '$remoteStage'"
        if ($LASTEXITCODE -ne 0) { throw 'Live-dev apply failed. The batch will be retried.' }
        $applied = $true
        Write-Host "[$(Get-Date -Format 'HH:mm:ss')] Synced $($Changed.Count) source files; removed $($Deleted.Count)."
    } finally {
        if (-not $applied -and $null -ne $remoteStage -and $remoteStage -match '^/tmp/kaevcms-live-dev\.[A-Za-z0-9]+$') {
            & ssh @sshOptions $SshHost "test ! -L '$remoteStage' && rm -rf -- '$remoteStage'" | Out-Null
            if ($LASTEXITCODE -ne 0) { Write-Warning 'Remote live-dev staging cleanup failed.' }
        }
        $resolvedBatch = [System.IO.Path]::GetFullPath($batch)
        if (-not $resolvedBatch.StartsWith($temporaryRoot + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) { throw 'Unexpected live-dev cleanup path.' }
        if (Test-Path -LiteralPath $resolvedBatch) { Remove-Item -LiteralPath $resolvedBatch -Recurse -Force }
    }
}

& ssh @sshOptions $SshHost "test ! -L '$RemoteRoot' && test -f '$RemoteRoot/artisan' && test -f '$RemoteRoot/VERSION' && test -d '$RemoteRoot/public' && test -w '$RemoteRoot' && command -v rsync >/dev/null && command -v php >/dev/null"
if ($LASTEXITCODE -ne 0) { throw 'SSH key access, remote project ownership, PHP or rsync is not ready.' }
Write-Host "Live dev: $SourceRoot -> ${SshHost}:$RemoteRoot. Save a source file, then refresh the site. Ctrl+C stops synchronization."
do {
    try {
        $current = @{}
        $changed = @()
        foreach ($file in @(Get-KaevCmsLiveDevFiles -SourceRoot $SourceRoot)) {
            $current[$file.Relative] = $file.Stamp
            if (-not $previous.ContainsKey($file.Relative) -or $previous[$file.Relative] -ne $file.Stamp) { $changed += $file }
        }
        $deleted = @($previous.Keys | Where-Object { -not $current.ContainsKey($_) })
        if ($changed.Count -gt 0 -or $deleted.Count -gt 0) { Send-LiveDevBatch -Changed $changed -Deleted $deleted }
        $previous = $current
    } catch {
        if ($Once) { throw }
        Write-Warning $_.Exception.Message
    }
    if (-not $Once) { Start-Sleep -Seconds $PollSeconds }
} while (-not $Once)
