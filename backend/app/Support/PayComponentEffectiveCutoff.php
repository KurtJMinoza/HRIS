<?php

namespace App\Support;

/**
 * Pay component availability end date tied to a payroll cutoff period (not ad-hoc calendar dates).
 *
 * Stored on {@see \App\Models\PayComponent::$metadata} as `effective_until_cutoff`.
 * {@see \App\Models\PayComponent::$effective_to} mirrors cut_off_end_date for payroll queries.
 */
final class PayComponentEffectiveCutoff
{
    public const META_KEY = 'effective_until_cutoff';

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array<string, mixed>|null
     */
    public static function normalize(?array $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $start = trim((string) ($raw['cut_off_start_date'] ?? ''));
        $end = trim((string) ($raw['cut_off_end_date'] ?? ''));
        if ($start === '' || $end === '') {
            return null;
        }

        $payCycleId = isset($raw['pay_cycle_id']) && is_numeric($raw['pay_cycle_id'])
            ? (int) $raw['pay_cycle_id']
            : null;
        $payDate = trim((string) ($raw['pay_date'] ?? ''));
        $cycleLabel = trim((string) ($raw['cycle_label'] ?? ''));
        $payCycleName = trim((string) ($raw['pay_cycle_name'] ?? ''));

        return [
            'pay_cycle_id' => $payCycleId > 0 ? $payCycleId : null,
            'pay_cycle_name' => $payCycleName !== '' ? $payCycleName : null,
            'cut_off_start_date' => $start,
            'cut_off_end_date' => $end,
            'pay_date' => $payDate !== '' ? $payDate : null,
            'cycle_label' => $cycleLabel !== '' ? $cycleLabel : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated  Mutated in place (metadata + effective_* columns).
     */
    public static function applyEffectiveDates(array &$validated): void
    {
        $meta = is_array($validated['metadata'] ?? null) ? $validated['metadata'] : [];
        $cutoff = self::normalize($meta[self::META_KEY] ?? null);

        if ($cutoff !== null) {
            $meta[self::META_KEY] = $cutoff;
            $validated['metadata'] = $meta;
            $validated['effective_from'] = null;
            $validated['effective_to'] = $cutoff['cut_off_end_date'];

            return;
        }

        unset($meta[self::META_KEY]);
        $validated['metadata'] = $meta !== [] ? $meta : null;
        $validated['effective_from'] = null;
        if (array_key_exists('effective_to', $validated)) {
            $validated['effective_to'] = null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $cutoff
     */
    public static function displayLabel(?array $cutoff, ?string $legacyEffectiveTo = null): ?string
    {
        $normalized = self::normalize($cutoff);
        if ($normalized !== null) {
            if (! empty($normalized['cycle_label'])) {
                $name = $normalized['pay_cycle_name'] ?? null;

                return $name
                    ? sprintf('%s · %s', $name, $normalized['cycle_label'])
                    : (string) $normalized['cycle_label'];
            }

            $line = sprintf(
                '%s – %s',
                $normalized['cut_off_start_date'],
                $normalized['cut_off_end_date']
            );
            if (! empty($normalized['pay_date'])) {
                $line .= ' · Pay '.$normalized['pay_date'];
            }

            return $line;
        }

        $legacy = trim((string) ($legacyEffectiveTo ?? ''));

        return $legacy !== '' ? 'Until '.$legacy.' (legacy date)' : null;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>|null
     */
    public static function fromMetadata(?array $metadata): ?array
    {
        if (! is_array($metadata)) {
            return null;
        }

        return self::normalize($metadata[self::META_KEY] ?? null);
    }
}
