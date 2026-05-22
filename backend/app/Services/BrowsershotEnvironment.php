<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Spatie\Browsershot\Browsershot;

class BrowsershotEnvironment
{
    /**
     * Apply Node/Puppeteer/Chrome settings for headless PDF rendering.
     */
    public function configure(Browsershot $shot): Browsershot
    {
        $nodeModules = $this->resolveNodeModulePath();
        if ($nodeModules !== null) {
            $shot->setNodeModulePath($nodeModules);
        }

        $nodeBinary = trim((string) config('services.browsershot.node_binary', ''));
        if ($nodeBinary !== '') {
            $shot->setNodeBinary($nodeBinary);
        }

        $npmBinary = trim((string) config('services.browsershot.npm_binary', ''));
        if ($npmBinary !== '') {
            $shot->setNpmBinary($npmBinary);
        }

        $chromePath = $this->resolveChromePath();
        if ($chromePath !== null) {
            $shot->setChromePath($chromePath);
        }

        return $shot;
    }

    public function resolveChromePath(): ?string
    {
        $configured = trim((string) config('services.browsershot.chrome_path', ''));
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }

        foreach ($this->candidateChromePaths() as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    public function resolveNodeModulePath(): ?string
    {
        foreach ($this->candidateNodeModulePaths() as $path) {
            $puppeteerCore = $path.DIRECTORY_SEPARATOR.'puppeteer-core';
            $puppeteer = $path.DIRECTORY_SEPARATOR.'puppeteer';
            if (is_dir($puppeteerCore) || is_dir($puppeteer)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function candidateNodeModulePaths(): array
    {
        $paths = [];

        $backendModules = realpath(base_path('node_modules'));
        if (is_string($backendModules) && $backendModules !== '') {
            $paths[] = $backendModules;
        }

        $frontendModules = realpath(base_path('../frontend/node_modules'));
        if (is_string($frontendModules) && $frontendModules !== '') {
            $paths[] = $frontendModules;
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return list<string>
     */
    private function candidateChromePaths(): array
    {
        $paths = [];

        foreach ($this->puppeteerCacheDirectories() as $cacheDir) {
            $paths = array_merge($paths, $this->findChromeExecutablesInDirectory($cacheDir));
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $paths = array_merge($paths, [
                'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
                'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
                'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
                'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
            ]);
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $paths = array_merge($paths, [
                '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
                '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
                '/Applications/Chromium.app/Contents/MacOS/Chromium',
            ]);
        } else {
            $paths = array_merge($paths, [
                '/usr/bin/google-chrome',
                '/usr/bin/google-chrome-stable',
                '/usr/bin/chromium',
                '/usr/bin/chromium-browser',
                '/snap/bin/chromium',
            ]);
        }

        return array_values(array_unique(array_filter($paths)));
    }

    /**
     * @return list<string>
     */
    private function puppeteerCacheDirectories(): array
    {
        $dirs = [];

        $configured = trim((string) config('services.browsershot.puppeteer_cache_dir', ''));
        if ($configured !== '') {
            $dirs[] = $configured;
        }

        $home = getenv('USERPROFILE') ?: getenv('HOME') ?: null;
        if (is_string($home) && $home !== '') {
            $dirs[] = $home.DIRECTORY_SEPARATOR.'.cache'.DIRECTORY_SEPARATOR.'puppeteer';
        }

        $dirs[] = base_path('node_modules'.DIRECTORY_SEPARATOR.'puppeteer'.DIRECTORY_SEPARATOR.'.local-chromium');

        return array_values(array_unique(array_filter($dirs, static fn (string $dir): bool => $dir !== '')));
    }

    /**
     * @return list<string>
     */
    private function findChromeExecutablesInDirectory(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $matches = [];
        $needles = ['chrome-headless-shell.exe', 'chrome.exe', 'chromium.exe', 'msedge.exe', 'chrome-headless-shell', 'chrome', 'chromium'];

        try {
            foreach (File::allFiles($directory) as $file) {
                $basename = Str::lower($file->getFilename());
                if (! in_array($basename, $needles, true)) {
                    continue;
                }

                $path = $file->getPathname();
                if (! is_file($path)) {
                    continue;
                }

                if (PHP_OS_FAMILY === 'Windows' || is_executable($path)) {
                    $matches[] = $path;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        usort($matches, function (string $a, string $b): int {
            $score = static function (string $path): int {
                $lower = Str::lower($path);

                return match (true) {
                    str_contains($lower, 'chrome-headless-shell') => 0,
                    str_contains($lower, 'chrome.exe') => 1,
                    str_contains($lower, 'msedge.exe') => 2,
                    default => 3,
                };
            };

            return $score($a) <=> $score($b);
        });

        return $matches;
    }
}
