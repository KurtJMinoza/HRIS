<?php

namespace App\Console\Commands;

use App\Services\TimeAndDateHolidayService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestHolidaysApiCommand extends Command
{
    protected $signature = 'holidays:test {year=2026}';

    protected $description = 'Test Time and Date Holidays API and show raw response';

    public function handle(TimeAndDateHolidayService $service): int
    {
        $year = (int) $this->argument('year');
        $accessKey = config('services.timedate.access_key');
        $secretKey = config('services.timedate.secret_key');

        $this->info("Testing Time and Date API for Philippines {$year}...");
        $this->newLine();

        if (! $accessKey || ! $secretKey) {
            $this->error('TIMEDATE_ACCESS_KEY and TIMEDATE_SECRET_KEY must be set in .env');

            return 1;
        }

        $url = 'https://api.xmltime.com/holidays';
        $params = [
            'version' => 3,
            'accesskey' => $accessKey,
            'secretkey' => $secretKey,
            'country' => 'ph',
            'year' => $year,
            'types' => 'federal,federallocal,religious,observance',
        ];

        $response = Http::timeout(15)->get($url, $params);

        $this->line('HTTP Status: '.$response->status());
        $this->newLine();

        $body = $response->body();
        $data = $response->json();

        if (! $response->successful()) {
            $this->error('API request failed.');
            $this->line($body);

            return 1;
        }

        if (isset($data['errors']) && ! empty($data['errors'])) {
            $this->error('API returned errors:');
            $this->line(json_encode($data['errors'], JSON_PRETTY_PRINT));

            return 1;
        }

        $holidays = $data['holidays'] ?? [];
        $this->info('Raw holidays count: '.count($holidays));

        if (count($holidays) > 0 && isset($holidays[0])) {
            $this->newLine();
            $this->line('First holiday structure:');
            $this->line(json_encode($holidays[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        $this->newLine();
        $this->info('Mapped result (from service):');
        $mapped = $service->getPhilippineHolidays($year);
        $this->line(json_encode($mapped, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return 0;
    }
}
