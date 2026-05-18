$ErrorActionPreference = 'Stop'

Write-Host "Starting Laravel HRIS..." -ForegroundColor Cyan

$backendPath = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $backendPath

function Get-WorkerCount([string] $name) {
  $value = [Environment]::GetEnvironmentVariable($name)
  if ($value) {
    $count = [int]$value
    if ($count -gt 0) { return $count }
  }
  return 1
}

function Start-QueueWorker([string] $queue, [int] $timeout, [int] $count) {
  for ($i = 1; $i -le $count; $i++) {
    Start-Process php -ArgumentList "artisan queue:work redis --queue=$queue --timeout=$timeout --sleep=1 --tries=2" -WorkingDirectory $backendPath -WindowStyle Hidden | Out-Null
  }
}

Write-Host "Starting PHP server on :8000" -ForegroundColor Yellow
Start-Process php -ArgumentList "artisan serve --port=8000" -WorkingDirectory $backendPath -WindowStyle Hidden | Out-Null

Write-Host "Starting Redis queue workers" -ForegroundColor Yellow
Start-QueueWorker "payroll" 300 (Get-WorkerCount "PAYROLL_QUEUE_WORKERS")
Start-QueueWorker "payslip" 300 (Get-WorkerCount "PAYSLIP_QUEUE_WORKERS")
Start-QueueWorker "face-registration" 180 (Get-WorkerCount "FACE_QUEUE_WORKERS")
Start-QueueWorker "default" 120 (Get-WorkerCount "DEFAULT_QUEUE_WORKERS")

Write-Host "Laravel HRIS services started." -ForegroundColor Green
Write-Host "Tip: scale workers with PAYROLL_QUEUE_WORKERS, PAYSLIP_QUEUE_WORKERS, FACE_QUEUE_WORKERS, DEFAULT_QUEUE_WORKERS." -ForegroundColor DarkGray
