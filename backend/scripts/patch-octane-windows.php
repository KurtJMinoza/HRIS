<?php

declare(strict_types=1);

$posixOriginal = <<<'PHP'
    public function kill(int $processId, int $signal)
    {
        return posix_kill($processId, $signal);
    }
PHP;

$posixLegacyWindowsPatch = <<<'PHP'
    public function kill(int $processId, int $signal)
    {
        if (! function_exists('posix_kill')) {
            if (PHP_OS_FAMILY !== 'Windows') {
                return false;
            }

            if ($signal === 0) {
                exec('tasklist /FI "PID eq '.$processId.'" /FO CSV /NH', $output);

                return isset($output[0]) && str_contains($output[0], (string) $processId);
            }

            exec('taskkill /PID '.$processId.' /T /F', $output, $exitCode);

            return $exitCode === 0;
        }

        return posix_kill($processId, $signal);
    }
PHP;

$posixWindowsPatch = <<<'PHP'
    public function kill(int $processId, int $signal)
    {
        if (! function_exists('posix_kill')) {
            if (PHP_OS_FAMILY !== 'Windows') {
                return false;
            }

            if ($signal === 0) {
                return $this->windowsProcessAlive($processId);
            }

            return $this->windowsKillProcessTree($processId);
        }

        return posix_kill($processId, $signal);
    }

    private function windowsProcessAlive(int $processId): bool
    {
        ['output' => $output] = $this->runWindowsCommand([
            'tasklist',
            '/FI',
            'PID eq '.$processId,
            '/FO',
            'CSV',
            '/NH',
        ]);

        return str_contains($output, (string) $processId);
    }

    private function windowsKillProcessTree(int $processId): bool
    {
        ['exitCode' => $exitCode] = $this->runWindowsCommand([
            'taskkill',
            '/PID',
            (string) $processId,
            '/T',
            '/F',
        ]);

        return $exitCode === 0;
    }

    /**
     * @param  list<string>  $command
     * @return array{output: string, exitCode: int}
     */
    private function runWindowsCommand(array $command): array
    {
        $process = @proc_open(
            $command,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true, 'suppress_errors' => true],
        );

        if (! is_resource($process)) {
            return ['output' => '', 'exitCode' => 1];
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'output' => is_string($output) ? $output : '',
            'exitCode' => $exitCode,
        ];
    }
PHP;

$files = [
    'vendor/laravel/octane/src/Commands/Concerns/InteractsWithServers.php' => [
        "    public function getSubscribedSignals(): array\n    {\n        return [SIGINT, SIGTERM, SIGHUP];\n    }" => "    public function getSubscribedSignals(): array\n    {\n        if (PHP_OS_FAMILY === 'Windows' || ! extension_loaded('pcntl')) {\n            return [];\n        }\n\n        return [SIGINT, SIGTERM, SIGHUP];\n    }",
    ],
    'vendor/laravel/octane/src/RoadRunner/Concerns/FindsRoadRunnerBinary.php' => [
        "        if (file_exists(base_path('rr'))) {\n            return base_path('rr');\n        }\n\n        if (! is_null(\$roadRunnerBinary" => "        if (file_exists(base_path('rr'))) {\n            return base_path('rr');\n        }\n\n        if (PHP_OS_FAMILY === 'Windows' && file_exists(base_path('rr.exe'))) {\n            return base_path('rr.exe');\n        }\n\n        if (! is_null(\$roadRunnerBinary",
    ],
    'vendor/laravel/octane/src/Commands/Concerns/InstallsRoadRunnerDependencies.php' => [
        'if (extension_loaded(\'pcntl\') && $e->getSignal() !== SIGINT)' => 'if (extension_loaded(\'pcntl\') && defined(\'SIGINT\') && $e->getSignal() !== SIGINT)',
    ],
    'vendor/laravel/octane/src/PosixExtension.php' => [
        $posixOriginal => $posixWindowsPatch,
        $posixLegacyWindowsPatch => $posixWindowsPatch,
    ],
];

$basePath = dirname(__DIR__);
$patched = 0;

foreach ($files as $relativePath => $replacements) {
    $path = $basePath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

    if (! is_file($path)) {
        continue;
    }

    $contents = file_get_contents($path);

    foreach ($replacements as $search => $replace) {
        if (str_contains($contents, $search)) {
            $contents = str_replace($search, $replace, $contents);
            $patched++;
        }
    }

    file_put_contents($path, $contents);
}

if ($patched > 0) {
    fwrite(STDOUT, "Applied Octane Windows compatibility patches ({$patched}).\n");
}
