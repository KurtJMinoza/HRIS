<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    // -------------------------------------------------------------------------
    // Phone-number utilities (provider-agnostic)
    // -------------------------------------------------------------------------

    /**
     * Philippine mobile: +63 9XX XXX XXXX (accept +639XXXXXXXXX or 09XXXXXXXXX).
     * Normalize to E.164 (+639XXXXXXXXX, no spaces) for storage/display/Twilio.
     */
    public static function normalizePhone(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', $value);
        // Accept 11-digit local format starting with 09XXXXXXXXX (convert to +639XXXXXXXXX)
        if (strlen($digits) === 11 && str_starts_with($digits, '09')) {
            $digits = substr($digits, 1); // drop leading 0 → 9XXXXXXXXX (10 digits)
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            return '+63'.$digits;
        }
        if (strlen($digits) === 12 && str_starts_with($digits, '639')) {
            return '+'.$digits;
        }

        return null;
    }

    /** E.164 (+639XXXXXXXXX) → digits-only (639XXXXXXXXX). */
    public static function toApiNumber(?string $e164): ?string
    {
        if ($e164 === null || $e164 === '') {
            return null;
        }
        $normalized = self::normalizePhone($e164);
        if ($normalized === null) {
            return null;
        }

        return preg_replace('/\D/', '', $normalized);
    }

    /** Validate Philippine mobile format. Accepts only +63 9XXXXXXXXX (optionally with a space after +63). */
    public static function isValidPhilippineMobile(?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return false;
        }

        return (bool) preg_match('/^\+63\s?9\d{9}$/u', trim($value));
    }

    // -------------------------------------------------------------------------
    // Send helpers
    // -------------------------------------------------------------------------

    /** Send SMS to a user's stored phone_number. No-op if phone missing or SMS not configured. */
    public function sendToUser(User $user, string $message): bool
    {
        $phone = self::normalizePhone($user->phone_number ?? '');
        if ($phone === null || $phone === '') {
            Log::debug('SMS skipped: user has no valid phone number.', [
                'user_id' => $user->id,
                'raw_phone' => $user->phone_number,
            ]);

            return false;
        }

        return $this->send($phone, $message);
    }

    /** Send SMS to an E.164 number. Returns true on success. */
    public function send(string $to, string $message): bool
    {
        return $this->sendWithResult($to, $message)['success'];
    }

    /**
     * Send SMS via custom HTTP API and return a structured audit result.
     *
     * @return array{success: bool, to_number: string, http_status: int|null, response_body: string|null, error_message: string|null}
     */
    public function sendWithResult(string $to, string $message): array
    {
        $config = config('services.philsms', []);
        $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        $apiToken = (string) ($config['token'] ?? '');
        $senderId = (string) ($config['sender_id'] ?? 'PhilSMS');

        // Normalize to E.164 then to PhilSMS recipient format (digits only, e.g. 639XXXXXXXXX)
        $toE164 = self::normalizePhone($to) ?? $to;
        $digits = preg_replace('/\D/', '', (string) $toE164);
        $recipient = null;
        if ($digits !== null && $digits !== '') {
            if (str_starts_with($digits, '0') && strlen($digits) === 11) {
                // 09XXXXXXXXX -> 639XXXXXXXXX
                $recipient = '63'.substr($digits, 1);
            } elseif (str_starts_with($digits, '63')) {
                $recipient = $digits;
            } elseif (str_starts_with($digits, '9') && strlen($digits) === 10) {
                // 9XXXXXXXXX -> 639XXXXXXXXX
                $recipient = '63'.$digits;
            }
        }

        $default = [
            'success' => false,
            'to_number' => $toE164,
            'http_status' => null,
            'response_body' => null,
            'error_message' => null,
        ];

        if ($baseUrl === '' || $apiToken === '') {
            Log::debug('SMS skipped: PhilSMS API credentials not configured.', [
                'base_url_set' => $baseUrl !== '',
                'token_set' => $apiToken !== '',
            ]);
            $default['error_message'] = 'SMS not configured (missing PhilSMS base URL or token).';

            return $default;
        }

        if ($recipient === null || $recipient === '') {
            Log::debug('SMS skipped: invalid recipient phone number.', [
                'to' => $to,
                'normalized' => $toE164,
            ]);
            $default['error_message'] = 'Invalid recipient phone number.';

            return $default;
        }

        try {
            $endpoint = $baseUrl.'/sms/send';

            Log::info('SMS send attempt', [
                'url' => $endpoint,
                'to' => $toE164,
                'recipient' => $recipient,
                'message_length' => strlen($message),
            ]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiToken,
                'Accept' => 'application/json',
            ])->post($endpoint, [
                'recipient' => $recipient,
                'sender_id' => $senderId,
                'type' => 'plain',
                'message' => $message,
            ]);

            $status = $response->status();
            $body = (string) $response->getBody();

            $json = null;
            if ($body !== '') {
                try {
                    $json = $response->json();
                } catch (\Throwable) {
                    $json = null;
                }
            }

            // PhilSMS returns 2xx HTTP status with a data payload; no explicit "success" flag.
            $success = $response->successful();
            $errorMessage = null;
            if (! $success && is_array($json)) {
                $errorMessage = $json['message'] ?? $json['error'] ?? null;
            }

            if (! $success) {
                Log::warning('SMS API error', [
                    'to' => $toE164,
                    'recipient' => $recipient,
                    'status' => $status,
                    'body' => $body,
                ]);
            } else {
                Log::info('SMS sent successfully', [
                    'to' => $toE164,
                    'recipient' => $recipient,
                    'status' => $status,
                ]);
            }

            return [
                'success' => $success,
                'to_number' => $toE164,
                'http_status' => $status,
                'response_body' => $body !== '' ? $body : null,
                'error_message' => $success ? null : ($errorMessage ?: 'SMS API request failed.'),
            ];
        } catch (\Throwable $e) {
            Log::warning('SMS API unexpected failure', [
                'to' => $toE164,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'to_number' => $toE164,
                'http_status' => null,
                'response_body' => null,
                'error_message' => $e->getMessage(),
            ];
        }
    }
}
