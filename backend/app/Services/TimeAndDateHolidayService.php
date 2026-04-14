<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches Philippine holidays from Time and Date API (api.xmltime.com/holidays).
 * Requires access key + secret from https://dev.timeanddate.com/account/accesskey
 */
class TimeAndDateHolidayService
{
    public function __construct(
        protected ?string $accessKey = null,
        protected ?string $secretKey = null,
        protected string $baseUrl = 'https://api.xmltime.com'
    ) {
        $this->accessKey ??= config('services.timedate.access_key');
        $this->secretKey ??= config('services.timedate.secret_key');
        $this->baseUrl = rtrim(config('services.timedate.base_url', $this->baseUrl), '/');
    }

    /**
     * Fetch holidays for Philippines for a given year.
     *
     * @return array<int, array{date: string, name: string, type: string, description?: string}>
     */
    public function getPhilippineHolidays(int $year): array
    {
        if (! $this->accessKey || ! $this->secretKey) {
            Log::warning('Time and Date API: missing access_key or secret_key in config');

            return [];
        }

        // Uses accesskey+secretkey (User/Password in URL). Requires "Allow insecure methods"
        // to be enabled at https://dev.timeanddate.com/account/accesskey
        $params = [
            'version' => 3,
            'accesskey' => $this->accessKey,
            'secretkey' => $this->secretKey,
            'country' => 'ph',
            'year' => $year,
            'types' => 'federal,federallocal,religious,observance',
        ];

        $url = "{$this->baseUrl}/holidays";

        try {
            $response = Http::timeout(15)->get($url, $params);

            if (! $response->successful()) {
                Log::warning('Time and Date API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            $data = $response->json();
            if (isset($data['errors']) && ! empty($data['errors'])) {
                Log::warning('Time and Date API returned errors', ['errors' => $data['errors']]);

                return [];
            }

            $holidays = $data['holidays'] ?? [];
            $mapped = [];

            foreach ($holidays as $h) {
                $dateIso = $h['date']['iso'] ?? null;
                if (! $dateIso) {
                    continue;
                }

                $name = $this->extractName($h);
                $type = $this->mapType($h);

                $mapped[] = [
                    'date' => $dateIso,
                    'name' => $name,
                    'type' => $type,
                    'description' => $this->extractDescription($h),
                ];
            }

            return $mapped;
        } catch (\Throwable $e) {
            Log::error('Time and Date API exception', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    protected function extractName(array $h): string
    {
        $names = $h['name'] ?? [];
        foreach ($names as $n) {
            if (($n['lang'] ?? '') === 'en') {
                return $n['text'] ?? '';
            }
        }

        return $names[0]['text'] ?? 'Holiday';
    }

    protected function extractDescription(array $h): ?string
    {
        $oneliners = $h['oneliner'] ?? [];
        foreach ($oneliners as $o) {
            if (($o['lang'] ?? '') === 'en') {
                return $o['text'] ?? null;
            }
        }

        return $oneliners[0]['text'] ?? null;
    }

    /**
     * Map Time and Date types to our types: regular | special.
     * Philippine DOLE: Regular = 200% if worked; Special = 130% if worked.
     * Heuristic: National/Federal -> regular; Observance/local -> special.
     */
    protected function mapType(array $h): string
    {
        $types = $h['types'] ?? [];
        $typeStr = is_array($types) ? implode(',', $types) : (string) $types;

        if (stripos($typeStr, 'National') !== false || stripos($typeStr, 'Federal') !== false) {
            return 'regular';
        }

        return 'special';
    }
}
