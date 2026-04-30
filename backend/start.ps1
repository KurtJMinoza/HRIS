$ErrorActionPreference = 'Stop'

Write-Host "Starting Laravel HRIS..." -ForegroundColor Cyan

$backendPath = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $backendPath

$workerCount = if ($env:QUEUE_WORKERS) { [int]$env:QUEUE_WORKERS } else { 1 }
if ($workerCount -lt 1) { $workerCount = 1 }

Write-Host "Starting PHP server on :8000" -ForegroundColor Yellow
Start-Process php -ArgumentList "artisan serve --port=8000" -WorkingDirectory $backendPath | Out-Null

Write-Host "Starting queue workers: $workerCount" -ForegroundColor Yellow
for ($i = 1; $i -le $workerCount; $i++) {
  Start-Process php -ArgumentList "artisan queue:work database --queue=face-registration,default --timeout=150 --sleep=1 --tries=3" -WorkingDirectory $backendPath | Out-Null
}

Write-Host "Laravel HRIS services started." -ForegroundColor Green
Write-Host "Tip: set QUEUE_WORKERS before running (example: `$env:QUEUE_WORKERS=8)." -ForegroundColor DarkGray
