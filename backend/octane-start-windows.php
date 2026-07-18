<?php

/**
 * Start Laravel Octane with RoadRunner on Windows.
 *
 * `php artisan octane:start` fails on Windows because Symfony's signal handling
 * requires the pcntl extension (SIGINT/SIGTERM/SIGHUP are unavailable).
 * This script bootstraps Laravel and launches rr.exe directly.
 *
 * Usage:
 *   php octane-start-windows.php
 *   php octane-start-windows.php --host=127.0.0.1 --port=8100 --workers=auto
 */

use Illuminate\Contracts\Console\Kernel;
use Laravel\Octane\RoadRunner\ServerStateFile;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

define('LARAVEL_START', microtime(true));

require __DIR__.'/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require_once __DIR__.'/bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

$options = parseOptions(array_slice($argv, 1));

$host = $options['host'] ?? env('OCTANE_HOST', config('octane.host', '127.0.0.1'));
$port = (string) ($options['port'] ?? env('OCTANE_PORT', defaultPortFromAppUrl(config('app.url'))));
$workers = (string) ($options['workers'] ?? config('octane.workers', 'auto'));
$maxRequests = (string) ($options['max-requests'] ?? config('octane.max_requests', 500));
$logLevel = $options['log-level'] ?? (app()->environment('local') ? 'debug' : 'warn');
$rrConfig = $options['rr-config'] ?? null;

$roadRunnerBinary = findRoadRunnerBinary();

if ($roadRunnerBinary === null) {
    fwrite(STDERR, "RoadRunner binary not found. Place rr.exe in the backend directory.\n");

    exit(1);
}

ensurePortIsAvailable($host, (int) $port);

$configPath = resolveRoadRunnerConfigPath($rrConfig);
$phpBinary = (new PhpExecutableFinder)->find() ?: PHP_BINARY;
$workerScript = base_path(config('octane.roadrunner.command', 'vendor/bin/roadrunner-worker'));
$workerCount = $workers === 'auto' ? 0 : (int) $workers;
$rpcPort = (int) ($options['rpc-port'] ?? ((int) $port - 1999));
$rpcHost = $options['rpc-host'] ?? $host;
$metricsPort = (int) ($options['metrics-port'] ?? ((int) $port + 1000));
$maxExecutionTime = config('octane.max_execution_time', 30).'s';

/** @var ServerStateFile $serverStateFile */
$serverStateFile = $app->make(ServerStateFile::class);

$serverStateFile->writeState([
    'appName' => config('app.name', 'Laravel'),
    'host' => $host,
    'port' => $port,
    'rpcPort' => $rpcPort,
    'workers' => $workerCount,
    'maxRequests' => $maxRequests,
    'octaneConfig' => config('octane'),
]);

$process = new Process(array_filter([
    $roadRunnerBinary,
    '-c', $configPath,
    '-o', 'version=3',
    '-o', 'http.address='.$host.':'.$port,
    '-o', 'server.command='.$phpBinary.','.$workerScript,
    '-o', 'http.pool.num_workers='.$workerCount,
    '-o', 'http.pool.max_jobs='.$maxRequests,
    '-o', 'rpc.listen=tcp://'.$rpcHost.':'.$rpcPort,
    '-o', 'metrics.address='.$rpcHost.':'.$metricsPort,
    '-o', 'http.pool.supervisor.exec_ttl='.$maxExecutionTime,
    '-o', 'http.static.dir='.public_path(),
    '-o', 'http.middleware='.config('octane.roadrunner.http_middleware', 'static'),
    '-o', 'logs.mode=production',
    '-o', 'logs.level='.$logLevel,
    '-o', 'logs.output=stdout',
    '-o', 'logs.encoding=json',
    'serve',
]), base_path(), [
    'APP_ENV' => app()->environment(),
    'APP_BASE_PATH' => base_path(),
    'LARAVEL_OCTANE' => 1,
]);

$process->start();

if ($process->getPid() !== null) {
    $serverStateFile->writeProcessId($process->getPid());
}

echo PHP_EOL;
echo '  Octane (RoadRunner) running on Windows'.PHP_EOL;
echo '  Local: http://'.$host.':'.$port.PHP_EOL;
echo '  Press Ctrl+C to stop'.PHP_EOL;
echo PHP_EOL;

while ($process->isRunning()) {
    echo $process->getIncrementalOutput();
    echo $process->getIncrementalErrorOutput();
    usleep(10_000);
}

echo $process->getIncrementalOutput();
echo $process->getIncrementalErrorOutput();

exit($process->getExitCode() ?? 1);

function parseOptions(array $args): array
{
    $options = [];

    foreach ($args as $arg) {
        if (! str_starts_with($arg, '--')) {
            continue;
        }

        $arg = substr($arg, 2);
        [$key, $value] = array_pad(explode('=', $arg, 2), 2, true);

        $options[$key] = $value === true ? '1' : $value;
    }

    return $options;
}

function defaultPortFromAppUrl(?string $appUrl): string
{
    if ($appUrl && preg_match('/:(\d+)(?:\/|$)/', $appUrl, $matches)) {
        return $matches[1];
    }

    return '8000';
}

function findRoadRunnerBinary(): ?string
{
    foreach ([base_path('rr.exe'), base_path('rr')] as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    $roadRunnerBinary = (new ExecutableFinder)->find('rr', null, [base_path()]);

    if ($roadRunnerBinary !== null && ! str_contains($roadRunnerBinary, 'vendor/bin/rr')) {
        return $roadRunnerBinary;
    }

    return null;
}

function resolveRoadRunnerConfigPath(?string $path): string
{
    if ($path && ! realpath($path)) {
        fwrite(STDERR, "Unable to locate RoadRunner config file: {$path}\n");

        exit(1);
    }

    if ($path) {
        return realpath($path);
    }

    $defaultPath = base_path('.rr.yaml');

    if (! is_file($defaultPath)) {
        touch($defaultPath);
    }

    return $defaultPath;
}

function ensurePortIsAvailable(string $host, int $port): void
{
    $connection = @fsockopen($host, $port);

    if (! is_resource($connection)) {
        return;
    }

    fclose($connection);

    fwrite(STDERR, "Port {$port} is already in use on {$host}. Stop the other server first or choose another port.\n");

    exit(1);
}
