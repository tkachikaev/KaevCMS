function Test-KaevCmsLiveDevPath {
    param([Parameter(Mandatory)][string] $Path)

    if ($Path -notmatch '^(app|config|lang|resources|routes|modules|themes|account-themes|public/(assets|themes|account-themes))/[A-Za-z0-9_@./-]+$') { return $false }
    if ($Path -match '(^|/)(\.|\.\.|\.[^/]*|database|migrations|storage|uploads|vendor|node_modules|cache)(/|$)') { return $false }
    if ($Path.Contains('//') -or $Path.EndsWith('/') -or $Path -match '\.(sqlite|db|key|pem|zip|gz)$') { return $false }
    return $true
}

function Get-KaevCmsLiveDevFiles {
    param([Parameter(Mandatory)][string] $SourceRoot)

    foreach ($directory in @('app', 'config', 'lang', 'resources', 'routes', 'modules', 'themes', 'account-themes', 'public/assets', 'public/themes', 'public/account-themes')) {
        $directoryPath = Join-Path $SourceRoot $directory
        if (-not (Test-Path -LiteralPath $directoryPath -PathType Container)) { continue }
        Get-ChildItem -LiteralPath $directoryPath -Recurse -File | ForEach-Object {
            $relative = $_.FullName.Substring($SourceRoot.Length + 1).Replace('\', '/')
            if (-not (Test-KaevCmsLiveDevPath -Path $relative)) { return }
            $probe = $_
            while ($null -ne $probe -and $probe.FullName -ne $SourceRoot) {
                if (($probe.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0) { throw "Live dev rejects symbolic links: $relative" }
                $probe = if ($probe -is [System.IO.FileInfo]) { $probe.Directory } else { $probe.Parent }
            }
            [pscustomobject]@{ Relative = $relative; FullName = $_.FullName; Stamp = "$($_.Length):$($_.LastWriteTimeUtc.Ticks)" }
        }
    }
}
