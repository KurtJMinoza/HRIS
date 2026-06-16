<?php

declare(strict_types=1);

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
        "    public function kill(int \$processId, int \$signal)\n    {\n        return posix_kill(\$processId, \$signal);\n    }" => "    public function kill(int \$processId, int \$signal)\n    {\n        if (! function_exists('posix_kill')) {\n            if (PHP_OS_FAMILY !== 'Windows') {\n                return false;\n            }\n\n            if (\$signal === 0) {\n                exec('tasklist /FI \"PID eq '.\$processId.'\" /FO CSV /NH', \$output);\n\n                return isset(\$output[0]) && str_contains(\$output[0], (string) \$processId);\n            }\n\n            exec('taskkill /PID '.\$processId.' /T /F', \$output, \$exitCode);\n\n            return \$exitCode === 0;\n        }\n\n        return posix_kill(\$processId, \$signal);\n    }",
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
