$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\..\support\live-dev-support.ps1"

foreach ($path in @('app/Models/User.php', 'resources/views/page.blade.php', 'public/assets/admin/css/components.css', 'modules/example/src/Feature.php', 'account-themes/luxury/views/index.blade.php')) {
    if (-not (Test-KaevCmsLiveDevPath -Path $path)) { throw "Live dev rejected a source path: $path" }
}
foreach ($path in @('.env', 'storage/app/private/data.json', 'public/uploads/user.png', 'database/database.sqlite', 'composer.lock', 'release.json', 'modules/example/database/migrations/change.php', 'modules/example/vendor/code.php', 'app/../.env', 'app//test.php', 'app/.secret', 'app/key.PEM', '/app/code.php', 'app\code.php', 'app/a;touch-secret.php')) {
    if (Test-KaevCmsLiveDevPath -Path $path) { throw "Live dev accepted a protected or unsafe path: $path" }
}

$fixture = Join-Path ([System.IO.Path]::GetTempPath()) ('kaevcms-live-dev-policy-' + [guid]::NewGuid().ToString('N'))
try {
    foreach ($path in @('app/Safe.php', 'public/assets/test.css', 'public/uploads/private.png', 'modules/example/database/migrations/blocked.php', 'storage/app/secret.txt')) {
        $fullPath = Join-Path $fixture $path
        New-Item -ItemType Directory -Path (Split-Path -Parent $fullPath) -Force | Out-Null
        'fixture' | Set-Content -LiteralPath $fullPath
    }
    $files = @(Get-KaevCmsLiveDevFiles -SourceRoot $fixture)
    if ($files.Count -ne 2) { throw 'Live dev must enumerate only the two source files, excluding runtime and migrations.' }
    if (@($files.Relative | Sort-Object) -join '|' -ne 'app/Safe.php|public/assets/test.css') { throw 'Live dev file selection does not match the actual source tree.' }
} finally {
    $resolvedFixture = [System.IO.Path]::GetFullPath($fixture)
    $temporaryDirectory = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath()).TrimEnd('\', '/')
    if (-not $resolvedFixture.StartsWith($temporaryDirectory + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) { throw 'Unexpected live-dev fixture cleanup path.' }
    if (Test-Path -LiteralPath $resolvedFixture) { Remove-Item -LiteralPath $resolvedFixture -Recurse -Force }
}
Write-Host 'Live-dev source and runtime protection checks completed successfully.'
