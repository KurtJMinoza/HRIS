<?php

namespace App\Support;

/**
 * PH Labor Code reference labels for overtime multipliers and holiday first-8h matrix (display / filing).
 *
 * Policy alignment: Labor Code Arts. 87, 93, 94; DOLE Omnibus Rules — same factors as
 * `config('payroll.rules')` and `PayrollRulesEngineService` (see docs/PAYROLL_RULES_ENGINE.md).
 */
class PhPayrollReference
{
    /** Rule codes allowed for employee OT filing dropdown (matches config payroll.rules). */
    public const OT_RULE_CODES = ['ORD', 'RD', 'RH', 'RHRD', 'SH', 'SHRD', 'DH', 'DHRD'];

    /**
     * OT multiplier options for employee overtime request form (day type × OT rate).
     *
     * @return array<int, array{code: string, day_type_label: string, ot_multiplier: float, first_8_multiplier: float}>
     */
    public static function otMultiplierDropdownOptions(): array
    {
        $rules = config('payroll.rules', []);
        $order = ['ORD', 'RD', 'RH', 'RHRD', 'SH', 'SHRD', 'DH', 'DHRD'];
        $dayLabels = [
            'ORD' => 'Ordinary Day',
            'RD' => 'Rest Day',
            'RH' => 'Regular Holiday',
            'RHRD' => 'Regular Holiday + Rest Day',
            'SH' => 'Special Holiday',
            'SHRD' => 'Special Holiday + Rest Day',
            'DH' => 'Double Holiday',
            'DHRD' => 'Double Holiday + Rest Day',
        ];

        $out = [];
        foreach ($order as $code) {
            $row = $rules[$code] ?? null;
            if (! $row) {
                continue;
            }
            $out[] = [
                'code' => $code,
                'day_type_label' => $dayLabels[$code] ?? $code,
                'ot_multiplier' => (float) ($row['ot'] ?? 1.25),
                'first_8_multiplier' => (float) ($row['first_8'] ?? 1.0),
            ];
        }

        return $out;
    }

    /**
     * First 8h multiplier matrix (holiday × rest × worked) — policy reference for Admin → Holidays UI.
     *
     * @return array<int, array{holiday_type: string, rest_day: bool|null, worked: bool|null, first_8_multiplier: float|string, note: string|null}>
     */
    public static function firstEightHourMatrix(): array
    {
        return [
            [
                'holiday_type' => 'None',
                'rest_day' => false,
                'worked' => true,
                'first_8_multiplier' => 1.00,
                'note' => null,
            ],
            [
                'holiday_type' => 'None',
                'rest_day' => true,
                'worked' => true,
                'first_8_multiplier' => 1.30,
                'note' => null,
            ],
            [
                'holiday_type' => 'Regular Holiday',
                'rest_day' => false,
                'worked' => false,
                'first_8_multiplier' => '—',
                'note' => 'Not worked: no pay (engine default)',
            ],
            [
                'holiday_type' => 'Regular Holiday',
                'rest_day' => false,
                'worked' => true,
                'first_8_multiplier' => 2.00,
                'note' => null,
            ],
            [
                'holiday_type' => 'Regular Holiday',
                'rest_day' => true,
                'worked' => true,
                'first_8_multiplier' => 2.60,
                'note' => null,
            ],
            [
                'holiday_type' => 'Special Holiday',
                'rest_day' => false,
                'worked' => false,
                'first_8_multiplier' => '—',
                'note' => 'No pay unless policy/CBA',
            ],
            [
                'holiday_type' => 'Special Holiday',
                'rest_day' => false,
                'worked' => true,
                'first_8_multiplier' => 1.30,
                'note' => null,
            ],
            [
                'holiday_type' => 'Special Holiday',
                'rest_day' => true,
                'worked' => true,
                'first_8_multiplier' => 1.50,
                'note' => null,
            ],
            [
                'holiday_type' => 'Special Working Holiday',
                'rest_day' => false,
                'worked' => false,
                'first_8_multiplier' => '—',
                'note' => 'Not worked: no pay (engine default)',
            ],
            [
                'holiday_type' => 'Special Working Holiday',
                'rest_day' => false,
                'worked' => true,
                'first_8_multiplier' => 1.30,
                'note' => null,
            ],
            [
                'holiday_type' => 'Special Working Holiday',
                'rest_day' => true,
                'worked' => true,
                'first_8_multiplier' => 1.50,
                'note' => null,
            ],
        ];
    }

    public static function otMultiplierTable(): array
    {
        $opts = self::otMultiplierDropdownOptions();

        return array_map(fn ($o) => [
            'day_type' => $o['day_type_label'],
            'ot_multiplier' => $o['ot_multiplier'],
            'rule_code' => $o['code'],
        ], $opts);
    }

    /**
     * Labels and multipliers for API (employee + admin overtime rows).
     *
     * @return array{ph_ot_rule: ?string, ph_ot_rule_label: ?string, ot_multiplier: ?float, first_8_multiplier: ?float}
     */
    public static function ruleMetaForOvertime(?string $code): array
    {
        if ($code === null || $code === '') {
            return [
                'ph_ot_rule' => null,
                'ph_ot_rule_label' => null,
                'ot_multiplier' => null,
                'first_8_multiplier' => null,
            ];
        }

        $rules = config('payroll.rules', []);
        $row = $rules[$code] ?? null;
        $label = null;
        foreach (self::otMultiplierDropdownOptions() as $opt) {
            if (($opt['code'] ?? '') === $code) {
                $label = $opt['day_type_label'] ?? null;
                break;
            }
        }

        return [
            'ph_ot_rule' => $code,
            'ph_ot_rule_label' => $label ?? $code,
            'ot_multiplier' => $row ? (float) ($row['ot'] ?? 0) : null,
            'first_8_multiplier' => $row ? (float) ($row['first_8'] ?? 0) : null,
        ];
    }

    /**
     * Hint lines for a stored holiday row (by DB type: regular|special|company).
     *
     * @return array<int, string>
     */
    public static function hintsForHolidayType(string $dbType): array
    {
        $t = strtolower(trim($dbType));

        if ($t === 'regular') {
            return [
                'Worked, ordinary workday: first 8h × 2.00; OT × 2.60.',
                'Worked, also scheduled rest day: first 8h × 2.60; OT × 3.38.',
                'Not worked: no daily pay unless paid leave or company policy/CBA.',
            ];
        }

        if ($t === 'special' || $t === 'company') {
            return [
                'Worked, ordinary workday: first 8h × 1.30; OT × 1.69.',
                'Worked, also scheduled rest day: first 8h × 1.50; OT × 1.95.',
                'Not worked: typically no pay unless company policy/CBA says otherwise.',
            ];
        }

        if ($t === 'special_working') {
            return [
                'Not worked: no daily pay (same absent default as special non-working).',
                'Worked, ordinary workday: first 8h × 1.30; OT × 1.69 (SH rule codes in the engine).',
                'Worked, also scheduled rest day: first 8h × 1.50; OT × 1.95 (SHRD).',
            ];
        }

        if ($t === 'double') {
            return [
                'Two regular holidays on the same date (calendar type "double"): first 8h × 3.00; OT × 3.90.',
                'Worked, also scheduled rest day: same multipliers as double holiday + RD (see rules engine DH/DHRD).',
                'Verify date against Admin → Holidays and DOLE proclamations.',
            ];
        }

        return [];
    }

    /**
     * Consolidated policy + engine snapshot for Admin API and integrations.
     * Multipliers reflect config; DB `payroll_rules` overrides at runtime via PayrollRulesEngineService.
     *
     * @return array<string, mixed>
     */
    public static function policyEngineReference(): array
    {
        return [
            'legal_basis' => [
                'Philippine Labor Code (Articles 87, 93, 94, and related provisions)',
                'DOLE Omnibus Rules Implementing the Labor Code',
            ],
            'multiplier_source' => 'config/payroll.php rules[]; overridden by payroll_rules when a row exists for the code.',
            'rules_hierarchy' => [
                '1) Holiday type from calendar (none → regular → special → double)',
                '2) Rest day from assigned schedule (Admin → Shifts)',
                '3) Attendance session (clock-in date owns the shift for segmentation)',
                '4) Segment net minutes: first 8h regular, remainder OT, ND 22:00–06:00 on applicable rate',
                '5) Apply first_8 and ot multipliers; ND +10% using nd_base (regular ND) and ot (OT ND)',
            ],
            'rules' => config('payroll.rules', []),
            'first_8_hour_matrix' => self::firstEightHourMatrix(),
            'ot_multiplier_matrix' => self::otMultiplierTable(),
            'night_differential' => [
                'window' => config('payroll.night_differential', []),
                'premium_on_hourly_fraction' => (float) config('payroll.nd_premium', 0.10),
            ],
            'ot_basis' => (string) config('payroll.ot_basis', 'schedule_end'),
            'ot_basis_note' => 'schedule_end: rendered OT = net work at/after scheduled shift end. eight_hour_net: legacy (net work − 8h).',
            'regular_hours_threshold' => (int) config('payroll.regular_hours_threshold', 8),
            'modules' => [
                'PayrollRulesEngineService' => 'resolveRuleCode(), getMultipliersForRule(), getHolidayType(), classifyDay()',
                'TimeSegmentationService' => 'segment() — regular/OT/ND split',
                'PayrollComputationService' => 'computeDayPayroll(), dailyComputationLogsForAdmin()',
                'PremiumReportService' => 'computeForEmployee() — premium report rows',
                'PremiumPayCalculatorService' => 'day-level premium pay alignment',
                'AttendanceSessionService' => 'getTimesForDate() — session pairing for payroll date',
                'EmployeeOvertimeController' => 'OT requests; ph_ot_rule must match rules codes',
                'Admin\\HolidayController' => 'Merged calendar; payroll_matrix + hints',
            ],
        ];
    }
}
