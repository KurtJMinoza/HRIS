$ErrorActionPreference = 'Stop'

$backendPath = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $backendPath

$phpArgs = @('octane-start-windows.php') + $args

# sockets is required by predis/redis on some Windows PHP builds
$socketsLoaded = & php -r "exit(extension_loaded('sockets') ? 0 : 1);"
if ($LASTEXITCODE -ne 0) {
    $phpArgs = @('-d', 'extension=sockets') + $phpArgs
}

& php @phpArgs
