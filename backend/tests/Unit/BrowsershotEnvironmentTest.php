<?php

namespace Tests\Unit;

use App\Services\BrowsershotEnvironment;
use Tests\TestCase;

class BrowsershotEnvironmentTest extends TestCase
{
    public function test_prefers_backend_node_modules_over_frontend(): void
    {
        $path = app(BrowsershotEnvironment::class)->resolveNodeModulePath();

        if ($path === null) {
            $this->markTestSkipped('Puppeteer is not installed in backend or frontend node_modules.');
        }

        $this->assertStringContainsString('node_modules', $path);
        $this->assertTrue(
            is_dir($path.DIRECTORY_SEPARATOR.'puppeteer')
            || is_dir($path.DIRECTORY_SEPARATOR.'puppeteer-core'),
        );
    }

    public function test_resolves_chrome_path_on_windows_when_installed(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows-only Chrome path probe.');
        }

        $path = app(BrowsershotEnvironment::class)->resolveChromePath();

        if ($path === null) {
            $this->markTestSkipped('No Chrome/Edge installation found on this machine.');
        }

        $this->assertFileExists($path);
    }
}
