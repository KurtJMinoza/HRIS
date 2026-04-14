<?php

namespace App\Services;

use App\Models\AttendanceCorrection;
use Carbon\Carbon;

/**
 * Aligns employee-facing and admin-facing labels with PH labor–friendly rules:
 * any valid punch counts as Present; incomplete pairs stay auditable via presence_issue / presence_label.
 */
class AttendancePresenceDisplayService
{
    /**
     * @return array{status: string, presence_label: ?string, presence_issue: string}
     */
    public function qualify(
        string $dateKey,
        string $todayDate,
        Carbon $nowTz,
        ?array $todaySchedule,
        string $status,
        mixed $effectiveTimeIn,
        mixed $effectiveTimeOut,
        ?AttendanceCorrection $correction,
        bool $isFuture,
    ): array {
        if (in_array($status, ['leave', 'halfday'], true)) {
            return [
                'status' => $status,
                'presence_label' => null,
                'presence_issue' => 'none',
            ];
        }

        $hasIn = $effectiveTimeIn !== null;
        $hasOut = $effectiveTimeOut !== null;
        $tzName = $nowTz->getTimezone()->getName();

        $pastShiftEnd = false;
        if ($todaySchedule && ! empty($todaySchedule['out']) && $hasIn && ! $hasOut) {
            $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($dateKey, $todaySchedule, $tzName);
            if ($scheduledEnd instanceof Carbon) {
                $pastShiftEnd = $dateKey < $todayDate || ($dateKey === $todayDate && $nowTz->greaterThan($scheduledEnd));
            }
        }
        if (! $pastShiftEnd && $dateKey < $todayDate && $hasIn && ! $hasOut) {
            $pastShiftEnd = true;
        }

        $presenceIssue = 'none';
        $presenceLabel = null;

        $hasPresenceFiling = $correction && (
            ($correction->reason_code !== null && $correction->reason_code !== '')
            || $correction->filed_at !== null
        );

        if ($correction && $correction->pending_approval) {
            $presenceIssue = 'correction_pending';
            $presenceLabel = 'Present (Pending Correction)';
        } elseif ($correction && $correction->approved && $hasPresenceFiling) {
            $presenceIssue = 'approved_correction';
            $presenceLabel = 'Present (Approved)';
        } elseif (($hasIn xor $hasOut) && (! $isFuture) && ($pastShiftEnd || ($hasOut && ! $hasIn))) {
            $presenceIssue = 'incomplete_pair';
            $presenceLabel = 'Present (Incomplete Records)';
        }

        $out = $status;

        // Legacy "incomplete" status → present for counts; UI uses presence_label/presence_issue.
        if ($out === 'incomplete') {
            $out = 'present';
        }

        // Clock-out without clock-in: should never be absent if a punch exists.
        if (! $hasIn && $hasOut && $out === 'absent') {
            $out = 'present';
        }

        return [
            'status' => $out,
            'presence_label' => $presenceLabel,
            'presence_issue' => $presenceIssue,
        ];
    }
}
