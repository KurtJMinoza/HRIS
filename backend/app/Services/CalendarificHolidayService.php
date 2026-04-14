<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches Philippine holidays from Calendarific API (calendarific.com).
 * Requires API key from https://calendarific.com/
 */
class CalendarificHolidayService
{
    public function __construct(
        protected ?string $apiKey = null,
        protected string $baseUrl = 'https://calendarific.com/api/v2'
    ) {
        $this->apiKey ??= config('services.calendarific.api_key');
        $this->baseUrl = rtrim(config('services.calendarific.base_url', $this->baseUrl), '/');
    }

    /**
     * Fetch holidays for Philippines for a given year.
     *
     * @return array<int, array{date: string, name: string, type: string, description?: string}>
     */
    public function getHolidays(int $year): array
    {
        if (! $this->apiKey || trim($this->apiKey) === '') {
            Log::warning('Calendarific API: missing api_key in config');

            return [];
        }

        $params = [
            'api_key' => trim($this->apiKey),
            'country' => 'PH',
            'year' => $year,
        ];

        $url = "{$this->baseUrl}/holidays";

        try {
            $response = Http::timeout(15)->get($url, $params);

            if (! $response->successful()) {
                Log::warning('Calendarific API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            $data = $response->json();
            $meta = $data['meta'] ?? [];
            if (($meta['code'] ?? 0) !== 200) {
                Log::warning('Calendarific API returned non-200', ['meta' => $meta]);

                return [];
            }

            $holidays = $data['response']['holidays'] ?? [];
            $mapped = [];

            foreach ($holidays as $h) {
                $dateIso = $h['date']['iso'] ?? null;
                if (! $dateIso) {
                    continue;
                }

                $mapped[] = [
                    'date' => $dateIso,
                    'name' => $h['name'] ?? 'Holiday',
                    'type' => $this->mapType($h),
                    'description' => $h['description'] ?? null,
                ];
            }

            return $mapped;
        } catch (\Throwable $e) {
            Log::error('Calendarific API exception', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Map Calendarific types to our types: regular | special.
     * Philippine DOLE: Regular = 200% if worked; Special = 130% if worked.
     * national/federal -> regular; observance/religious/local -> special.
     */
    protected function mapType(array $h): string
    {
        $types = $h['type'] ?? [];
        if (! is_array($types)) {
            $types = [$types];
        }
        $typeStr = implode(',', array_map('strtolower', $types));

        if (str_contains($typeStr, 'national') || str_contains($typeStr, 'federal')) {
            return 'regular';
        }

        return 'special';
    }
}
