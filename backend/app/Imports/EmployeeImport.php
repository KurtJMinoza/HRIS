<?php

namespace App\Imports;

use App\Enums\EmploymentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\EmployeeGovernmentId;
use App\Models\EmployeeTaxInfo;
use App\Models\PayCycle;
use App\Models\User;
use App\Models\WorkingSchedule;
use App\Services\DataScopeService;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class EmployeeImport implements ToCollection, WithHeadingRow
{
    /** Default login password for every row created by this import (hashed before save). */
    private const DEFAULT_IMPORT_PASSWORD = 'aci12345';

    /** @var list<string> */
    private const SCHEDULE_DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    /**
     * When several {@see WorkingSchedule} rows share the same clock, prefer names that look like the standard day template.
     *
     * @var list<string> Lowercase substrings, highest priority first.
     */
    private const IMPORT_SCHEDULE_NAME_PREFERENCE = [
        'regular day shift',
        'regular day',
        'day shift',
        'office shift',
        'default day',
    ];

    private int $imported = 0;

    private int $failed = 0;

    /** @var array<int, array{row:int,email:string,name:string,message:string}> */
    private array $errors = [];

    private int $processedRows = 0;

    /** @var list<int> */
    private array $createdUserIds = [];

    /**
     * Lazy cache: normalized schedule name key → id (built once per import for fast exact lookups).
     *
     * @var array<string, int>|null
     */
    private ?array $workingScheduleExactNameIndex = null;

    public function __construct(
        private readonly User $actor,
        private readonly DataScopeService $dataScopeService,
        private readonly string $importBatchId,
    ) {}

    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            $excelRowNumber = $index + 2; // heading row + 1-based index
            $data = $row->toArray();

            // Import every sheet row: empty cells are fine — mapRow supplies defaults (e.g. Unknown / Employee-{row}).
            $this->processedRows++;
            $this->importOneRowWithRecoveries($data, $excelRowNumber);
        }
    }

    /**
     * Import a single row with progressive relaxation (phone → email) for uniqueness collisions,
     * and org defaults so scoped HR importers (e.g. department heads) can create rows without org columns in the sheet.
     */
    private function importOneRowWithRecoveries(array $data, int $excelRowNumber): void
    {
        $plans = [
            [],
            ['phone' => null],
            ['phone' => null, 'email' => null],
        ];
        $lastIdx = count($plans) - 1;

        foreach ($plans as $idx => $plan) {
            try {
                $createdUserId = null;
                DB::transaction(function () use ($data, $excelRowNumber, $plan, &$createdUserId): void {
                    $payload = $this->applyImportOrgFallbacks($this->mapRow($data, $excelRowNumber));
                    if (array_key_exists('phone', $plan)) {
                        $payload['phone_number'] = $plan['phone'];
                    }
                    if (array_key_exists('email', $plan)) {
                        $payload['email'] = $plan['email'];
                    }
                    $payload['phone_number'] = $this->nullIfBlank($payload['phone_number'] ?? null);
                    $payload['email'] = $this->nullIfBlank($payload['email'] ?? null);
                    $this->assertScopedAccess($payload);
                    $user = $this->createUser($payload);
                    $createdUserId = (int) $user->id;
                    $this->syncEmployeeRecords($user, $payload);
                });
                if ($createdUserId !== null) {
                    $this->createdUserIds[] = $createdUserId;
                }
                $this->imported++;
                if ($plan !== []) {
                    Log::warning('Employee import row recovered with relaxed contact/org', [
                        'row' => $excelRowNumber,
                        'plan' => $plan,
                    ]);
                }

                return;
            } catch (\Throwable $e) {
                if ($idx === $lastIdx) {
                    $this->failed++;
                    $this->errors[] = [
                        'row' => $excelRowNumber,
                        'email' => (string) ($this->value($data, ['email']) ?? ''),
                        'name' => (string) ($this->value($data, ['full_name', 'first_name']) ?? ''),
                        'message' => $e->getMessage(),
                    ];
                    Log::warning('Employee import row skipped', [
                        'row' => $excelRowNumber,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * When the sheet omits org columns, inherit the importer's department / branch / company so
     * {@see DataScopeService::assertCanCreateEmployeeInOrg} succeeds for department and branch heads.
     */
    private function applyImportOrgFallbacks(array $payload): array
    {
        $actor = $this->actor;

        if ($actor->department_id && empty($payload['department_id'])) {
            $payload['department_id'] = (int) $actor->department_id;
            $dept = Department::query()->with('branch')->find($actor->department_id);
            if ($dept) {
                $payload['department'] = $payload['department'] ?: $dept->name;
                if (empty($payload['branch_id']) && $dept->branch_id) {
                    $payload['branch_id'] = (int) $dept->branch_id;
                }
                if (empty($payload['company_id']) && $dept->branch?->company_id) {
                    $payload['company_id'] = (int) $dept->branch->company_id;
                }
            }
        }

        if ($actor->branch_id && empty($payload['branch_id'])) {
            $payload['branch_id'] = (int) $actor->branch_id;
            $branch = Branch::query()->find($actor->branch_id);
            if ($branch && empty($payload['company_id'])) {
                $payload['company_id'] = (int) $branch->company_id;
            }
        }

        if ($actor->company_id && empty($payload['company_id'])) {
            $payload['company_id'] = (int) $actor->company_id;
        }

        return $payload;
    }

    public function summary(): array
    {
        return [
            'imported' => $this->imported,
            'failed' => $this->failed,
            'total_rows' => $this->processedRows,
            'errors' => $this->errors,
            'import_batch_id' => $this->importBatchId,
            'created_user_ids' => $this->createdUserIds,
        ];
    }

    private function mapRow(array $row, int $excelRowNumber): array
    {
        $row = $this->normalizeHeadingRowData($row);

        $fullName = $this->clean($this->value($row, ['full_name', 'fullname', 'name']));
        $first = $this->clean($this->value($row, ['first_name', 'firstname', 'given_name']));
        $middle = $this->clean($this->value($row, ['middle_name', 'middlename', 'middle']));
        $last = $this->clean($this->value($row, ['last_name', 'lastname', 'surname', 'family_name']));

        if (! $first || ! $last) {
            [$fallbackFirst, $fallbackMiddle, $fallbackLast] = $this->splitName($fullName);
            $first = $first ?: $fallbackFirst;
            $middle = $middle ?: $fallbackMiddle;
            $last = $last ?: $fallbackLast;
        }

        // Keep row importable even when name columns are partially missing.
        $first = $first ?: 'Unknown';
        $last = $last ?: 'Employee-'.$excelRowNumber;

        $first = $this->normalizeImportedPersonName($first);
        $last = $this->normalizeImportedPersonName($last);
        if ($middle !== null && $middle !== '') {
            $middle = $this->normalizeImportedPersonName($middle);
        }

        $rawEmail = $this->cleanContactImportCell($this->value($row, ['email', 'email_address']));
        $email = ($rawEmail !== null && filter_var($rawEmail, FILTER_VALIDATE_EMAIL))
            ? Str::lower($rawEmail)
            : null;

        $companyName = $this->clean($this->value($row, ['company', 'company_name']));
        $branchName = $this->clean($this->value($row, ['branch', 'branch_name']));
        $departmentName = $this->clean($this->value($row, ['department', 'department_name']));

        $company = $companyName ? Company::query()->whereRaw('LOWER(name)=?', [Str::lower($companyName)])->first() : null;
        $branch = $branchName ? Branch::query()->whereRaw('LOWER(name)=?', [Str::lower($branchName)])->first() : null;
        $department = $departmentName ? Department::query()->whereRaw('LOWER(name)=?', [Str::lower($departmentName)])->first() : null;

        $companyId = $company?->id ?? $branch?->company_id;
        $branchId = $branch?->id ?? $department?->branch_id;
        if (! $companyId && $branchId) {
            $companyId = Branch::query()->whereKey($branchId)->value('company_id');
        }

        $street = $this->importStreetFromRow($row);
        $barangay = $this->importBarangayFromRow($row);
        $city = $this->importCityFromRow($row);
        $province = $this->importProvinceFromRow($row);
        $postal = $this->importPostalFromRow($row);
        $homeAddress = $this->importHomeAddressFromRow($row);
        if (! $homeAddress) {
            $homeAddress = implode(', ', array_filter([$street, $barangay, $city, $province, $postal]));
            $homeAddress = $homeAddress !== '' ? $homeAddress : null;
        }

        return [
            'name' => $this->composeName($first, $middle, $last),
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'date_of_birth' => $this->parseDate($this->value($row, ['date_of_birth', 'dob', 'birth_date'])),
            'gender' => $this->normalizeImportedGender($this->importGenderFromRow($row)),
            // DB column is `civil_status` (UI: Civil Status). Spreadsheet "Marital Status" maps here — not `marital_status`.
            'civil_status' => $this->normalizeImportedCivilStatus($this->importCivilStatusFromRow($row)),
            'nationality' => $this->normalizeImportedNationality($this->importNationalityFromRow($row)),
            'email' => $this->nullIfBlank($email),
            'phone_number' => $this->nullIfBlank(
                $this->resolveUniquePhoneOrNull(
                    $this->normalizePhoneNumber(
                        $this->cleanContactImportCell($this->value($row, ['phone_number', 'phone', 'mobile']))
                    )
                )
            ),
            'home_address' => $homeAddress,
            'street_address' => $street,
            'barangay' => $barangay,
            'city' => $city,
            'province' => $province,
            'postal_code' => $postal,
            'employment_type' => $this->normalizeEmploymentType($this->value($row, [
                'employment_type',
                'type_of_employment',
                'employmenttype',
                'work_arrangement',
                'work_arrangement_type',
            ])),
            'employment_status' => $this->normalizeEmploymentStatus($this->importEmploymentStatusFromRow($row)),
            'employment_status_effective_date' => $this->parseDate($this->value($row, [
                'employment_status_effective_date',
                'employment_status_effectivity_date',
                'employment_status_effective',
                'status_effective_date',
                'effectivity_of_employment_status',
            ])),
            'hire_date' => $this->parseDate($this->value($row, [
                'date_hired',
                'hire_date',
                'date_of_hire',
                'date_joined',
                'joining_date',
                'start_date',
                'commencement_date',
                'employment_start_date',
            ])),
            'contract_start_date' => $this->parseDate($this->value($row, ['contract_start_date'])),
            'contract_end_date' => $this->parseDate($this->value($row, ['contract_end_date'])),
            'position' => $this->clean($this->value($row, ['position', 'job_title'])),
            'department' => $department?->name ?? $departmentName,
            'department_id' => $department?->id,
            'branch_id' => $branchId,
            'company_id' => $companyId,
            'supervisor' => $this->clean($this->value($row, ['supervisor', 'supervisor_name'])),
            'working_schedule' => $this->normalizeWorkingScheduleLabel($this->clean($this->value($row, [
                'working_schedule',
                'schedule',
                'schedule_name',
                'shift',
                'shift_name',
                'working_hours',
                'working_hours_description',
            ]))),
            'working_time_in' => $this->parseWorkingTime($this->clean($this->value($row, [
                'working_time_in',
                'time_in',
                'clock_in',
                'shift_start',
                'work_time_in',
            ]))),
            'working_time_out' => $this->parseWorkingTime($this->clean($this->value($row, [
                'working_time_out',
                'time_out',
                'clock_out',
                'shift_end',
                'work_time_out',
            ]))),
            'rest_days' => $this->normalizeRestDayKeys($this->clean($this->value($row, [
                'rest_days',
                'rest_day',
                'day_off',
                'days_off',
                'rest',
            ]))),
            'pay_schedule' => $this->clean($this->value($row, ['pay_schedule'])),
            'monthly_salary' => $this->parseDecimal($this->value($row, ['basic_salary', 'monthly_salary'])),
            'monthly_rate' => $this->parseDecimal($this->value($row, ['monthly_rate'])),
            'daily_rate' => $this->parseDecimal($this->value($row, ['daily_rate'])),
            'hourly_rate' => $this->parseDecimal($this->value($row, ['hourly_rate'])),
            'salary_effectivity_date' => $this->parseDate($this->value($row, ['salary_effectivity_date'])),
            'rice_allowance' => $this->parseDecimal($this->value($row, ['rice_allowance'])),
            'transport_allowance' => $this->parseDecimal($this->value($row, ['transportation_allowance', 'transport_allowance'])),
            'other_pay_components' => $this->clean($this->value($row, ['other_pay_components'])),
            'sss_number' => $this->clean($this->value($row, ['sss_number', 'sss'])),
            'philhealth_number' => $this->clean($this->value($row, ['philhealth_number', 'philhealth'])),
            'pagibig_number' => $this->clean($this->value($row, ['pag_ibig_number', 'pagibig_number', 'pagibig'])),
            'tin_number' => $this->clean($this->value($row, ['tin_number', 'tin'])),
            // Keep these non-null even when import columns are blank.
            'tax_regime' => $this->normalizeTaxRegime($this->value($row, ['tax_regime'])),
            'withholding_method' => $this->normalizeWithholdingMethod($this->value($row, ['withholding_method'])),
            'dependents' => $this->parseInt($this->value($row, ['dependents'])) ?? 0,
            'is_active' => $this->parseBoolean($this->value($row, ['active', 'is_active']), true),
        ];
    }

    private function assertScopedAccess(array $payload): void
    {
        $this->dataScopeService->assertCanCreateEmployeeInOrg(
            $this->actor,
            $payload['company_id'] ? (int) $payload['company_id'] : null,
            $payload['branch_id'] ? (int) $payload['branch_id'] : null,
            $payload['department_id'] ? (int) $payload['department_id'] : null
        );
    }

    private function createUser(array $payload): User
    {
        $emailForUser = $this->nullIfBlank($payload['email'] ?? null);
        $phoneForUser = $this->nullIfBlank($payload['phone_number'] ?? null);

        $username = $this->buildUniqueUsername($payload['first_name']);
        $supervisorId = $this->resolveSupervisorId($payload['supervisor']);
        $scheduleId = $this->resolveWorkingScheduleId($payload);
        $payCycleId = $this->resolvePayCycleId($payload['pay_schedule'], $payload['company_id']);

        $userAttributes = [
            'name' => $payload['name'],
            'first_name' => $payload['first_name'],
            'middle_name' => $payload['middle_name'],
            'last_name' => $payload['last_name'],
            'email' => $emailForUser,
            'username' => $username,
            'password' => Hash::make(self::DEFAULT_IMPORT_PASSWORD),
            'role' => User::ROLE_EMPLOYEE,
            'employee_import_batch_id' => $this->importBatchId,
            'date_of_birth' => $payload['date_of_birth'],
            'gender' => $payload['gender'],
            'civil_status' => $payload['civil_status'],
            'nationality' => $payload['nationality'],
            'phone_number' => $phoneForUser,
            'home_address' => $payload['home_address'],
            'full_address' => $payload['home_address'],
            'street_address' => $payload['street_address'],
            'barangay' => $payload['barangay'],
            'city' => $payload['city'],
            'province' => $payload['province'],
            'postal_code' => $payload['postal_code'],
            'employment_type' => $payload['employment_type'],
            'employment_status_effective_date' => $payload['employment_status_effective_date'],
            'hire_date' => $payload['hire_date'],
            'contract_start_date' => $payload['contract_start_date'],
            'contract_end_date' => $payload['contract_end_date'],
            'position' => $payload['position'],
            'department' => $payload['department'],
            'department_id' => $payload['department_id'],
            'branch_id' => $payload['branch_id'],
            'company_id' => $payload['company_id'],
            'supervisor_id' => $supervisorId,
            'working_schedule_id' => $scheduleId,
            'pay_cycle_id' => $payCycleId,
            'monthly_salary' => $payload['monthly_salary'],
            'monthly_rate' => $payload['monthly_rate'],
            'daily_rate' => $payload['daily_rate'],
            'hourly_rate' => $payload['hourly_rate'],
            'salary_effectivity_date' => $payload['salary_effectivity_date'],
            'is_active' => $payload['is_active'],
        ];

        $employmentStatus = $this->nullIfBlank($payload['employment_status'] ?? null);
        if ($employmentStatus !== null) {
            $userAttributes['employment_status'] = $employmentStatus;
        }

        $user = User::query()->create($userAttributes);

        // Defensive guard: imports with blank/invalid email must remain null (never synthesized).
        if ($emailForUser === null && $user->email !== null) {
            $user->forceFill(['email' => null])->saveQuietly();
        }

        $user->forceFill([
            'employee_code' => sprintf('EMP-%04d', (int) $user->id),
            'qr_token' => User::generateQrTokenFor($user),
            'qr_token_generated_at' => now(),
        ])->save();

        return $user;
    }

    private function syncEmployeeRecords(User $user, array $payload): void
    {
        EmployeeGovernmentId::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'sss_number' => $payload['sss_number'],
                'philhealth_number' => $payload['philhealth_number'],
                'pagibig_number' => $payload['pagibig_number'],
                'tin_number' => $payload['tin_number'],
            ]
        );

        EmployeeTaxInfo::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                // Defensive non-null defaults.
                'tax_regime' => $payload['tax_regime'] ?: 'standard_train',
                'withholding_method' => $payload['withholding_method'] ?: 'monthly',
                'dependents' => $payload['dependents'] ?? 0,
                'period_type' => 'monthly',
                'tax_table_version' => 'train_2018',
            ]
        );
    }

    private function value(array $row, array $aliases): mixed
    {
        foreach ($aliases as $alias) {
            $candidate = $this->normalizeHeaderKey($alias);
            foreach ($row as $key => $value) {
                if ($this->normalizeHeaderKey((string) $key) === $candidate) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Read the Employment Status cell: exact header aliases first, then the most specific matching column key
     * (prefer `employment_status` over shorter keys like `emp_status` so "Regular" is not taken from the wrong column).
     *
     * @param  array<string, mixed>  $row  Keys already normalized via {@see normalizeHeadingRowData()}.
     */
    private function importEmploymentStatusFromRow(array $row): ?string
    {
        $direct = $this->clean($this->value($row, [
            'employment_status',
            'employee_employment_status',
            'emp_employment_status',
            'current_employment_status',
            'emp_status',
            'job_status',
            'employee_status',
            'employment_state',
        ]));
        if ($direct !== null && $direct !== '') {
            return $direct;
        }

        foreach ([
            'employment_status',
            'employee_employment_status',
            'emp_employment_status',
            'current_employment_status',
            'emp_status',
            'job_status',
            'employee_status',
            'employment_state',
        ] as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }
            $t = $this->clean($row[$key]);
            if ($t !== null && $t !== '') {
                return $t;
            }
        }

        $bestKey = null;
        $bestLen = -1;
        foreach ($row as $key => $cellValue) {
            $k = (string) $key;
            if ($k === '' || ! str_contains($k, 'employment') || ! str_contains($k, 'status')) {
                continue;
            }
            if (str_contains($k, 'effective') || str_contains($k, 'effectivity')) {
                continue;
            }
            if (preg_match('/(history|remark|note|comment|reason|description|prev|previous|new)(_|$)/i', $k)) {
                continue;
            }
            $t = $this->clean($cellValue);
            if ($t === null || $t === '') {
                continue;
            }
            if (strlen($k) > $bestLen) {
                $bestLen = strlen($k);
                $bestKey = $k;
            }
        }

        return $bestKey !== null ? $this->clean($row[$bestKey]) : null;
    }

    /**
     * Prefer explicit "Gender" / "Sex" columns, then substring header match.
     */
    private function importGenderFromRow(array $row): ?string
    {
        $direct = $this->cleanPersonalField($this->value($row, [
            'gender',
            'sex',
            'employee_gender',
            'biological_sex',
            'legal_sex',
            'legal_gender',
            'gen',
            'employee_sex',
            'gender_identity',
            'sex_male_female',
            'gender_male_female',
        ]));
        if ($direct !== null && trim($direct) !== '') {
            return $direct;
        }

        return $this->cleanPersonalField($this->valueDemographic(
            $row,
            ['gender', 'sex', 'employee_gender', 'biological_sex', 'legal_sex', 'legal_gender', 'gen'],
            ['gender', 'legal_sex', 'biological_sex']
        ));
    }

    /**
     * Prefer "Marital Status" / "Civil Status" columns (stored on {@see User::$civil_status}).
     */
    private function importCivilStatusFromRow(array $row): ?string
    {
        $direct = $this->cleanPersonalField($this->value($row, [
            'marital_status',
            'civil_status',
            'marriage_status',
            'domestic_status',
            'civil_stat',
            'family_status',
            'spousal_status',
            'wedlock_status',
        ]));
        if ($direct !== null && trim($direct) !== '') {
            return $direct;
        }

        return $this->cleanPersonalField($this->valueDemographic(
            $row,
            [
                'marital_status',
                'civil_status',
                'marriage_status',
                'marital',
                'civil',
                'spouse_status',
                'marital_civil_status',
                'civil_marital_status',
                'marital_or_civil_status',
                'marital_status_civil_status',
                'civil_status_marital_status',
                'relationship_status',
            ],
            ['marital_status', 'civil_status', 'marital', 'civil']
        ));
    }

    /**
     * Prefer "Nationality" / "Citizenship" columns.
     */
    private function importNationalityFromRow(array $row): ?string
    {
        $direct = $this->cleanPersonalField($this->value($row, [
            'nationality',
            'citizenship',
            'country_of_citizenship',
            'country_of_nationality',
            'country_of_birth',
            'country_of_origin',
            'nationality_desc',
            'citizenship_country',
        ]));
        if ($direct !== null && trim($direct) !== '') {
            return $direct;
        }

        return $this->cleanPersonalField($this->valueDemographic(
            $row,
            [
                'nationality',
                'citizenship',
                'country_of_citizenship',
                'country_of_nationality',
                'country_of_birth',
                'country_of_origin',
                'nationality_country',
                'nationality_citizenship',
                'national',
                'citizen',
            ],
            ['nationality', 'citizenship', 'country_of']
        ));
    }

    /**
     * Street / line-1 style columns (common HR export and government-form labels).
     *
     * @param  array<string, mixed>  $row
     */
    private function importStreetFromRow(array $row): ?string
    {
        $direct = $this->cleanPersonalField($this->value($row, [
            'street',
            'street_address',
            'street_addr',
            'street_line_1',
            'street_line1',
            'address_street',
            'residential_street',
            'current_street',
            'home_street',
            'mailing_street',
            'present_street',
            'permanent_street',
            'address_line_1',
            'address_line1',
            'line_1',
            'line1',
            'blk_lot_street',
            'block_lot_street',
            'house_no_street',
            'house_number_and_street',
            'street_building',
            'building_street',
            'unit_street',
        ]));
        if ($direct !== null && $direct !== '') {
            return $direct;
        }

        return $this->cleanPersonalField($this->valueDemographic(
            $row,
            [],
            [
                'street_address',
                'street_line',
                'residential_street',
                'home_street',
                'current_street',
                'mailing_street',
                'address_line_1',
                'address_line1',
            ]
        ));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function importBarangayFromRow(array $row): ?string
    {
        $direct = $this->cleanPersonalField($this->value($row, [
            'barangay',
            'brgy',
            'barangay_district',
            'district',
            'subd',
            'subdivision',
            'village',
            'zone',
            'sitio',
            'purok',
            'barangay_name',
        ]));
        if ($direct !== null && $direct !== '') {
            return $direct;
        }

        return $this->cleanPersonalField($this->valueDemographic(
            $row,
            [],
            ['barangay', 'brgy', 'sitio', 'purok', 'subdivision', 'village', 'zone']
        ));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function importCityFromRow(array $row): ?string
    {
        $direct = $this->cleanPersonalField($this->value($row, [
            'city',
            'city_municipality',
            'municipality',
            'town',
            'town_city',
            'city_or_municipality',
            'place_of_residence',
            'residential_city',
            'home_city',
            'current_city',
            'mailing_city',
            'present_city',
            'permanent_city',
            'employee_city',
        ]));
        if ($direct !== null && $direct !== '') {
            return $direct;
        }

        return $this->cleanPersonalField($this->valueDemographic(
            $row,
            [],
            [
                'city_municipality',
                'home_city',
                'residential_city',
                'current_city',
                'mailing_city',
                'municipality',
                'town_city',
            ]
        ));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function importProvinceFromRow(array $row): ?string
    {
        $direct = $this->cleanPersonalField($this->value($row, [
            'province',
            'state',
            'state_province',
            'province_state',
            'region',
            'home_province',
            'residential_province',
            'current_province',
            'mailing_province',
            'present_province',
            'permanent_province',
            'employee_province',
        ]));
        if ($direct !== null && $direct !== '') {
            return $direct;
        }

        return $this->cleanPersonalField($this->valueDemographic(
            $row,
            [],
            [
                'home_province',
                'state_province',
                'province_of',
                'residential_province',
                'current_province',
                'mailing_province',
            ]
        ));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function importPostalFromRow(array $row): ?string
    {
        $direct = $this->cleanPersonalField($this->value($row, [
            'postal_code',
            'zip_code',
            'zip',
            'postal',
            'postcode',
            'zipcode',
            'postalcode',
            'zip_postal',
            'area_code',
            'postal_district',
        ]));
        if ($direct !== null && $direct !== '') {
            return $direct;
        }

        return $this->cleanPersonalField($this->valueDemographic(
            $row,
            [],
            ['postal_code', 'zip_code', 'postcode', 'zipcode', 'postal']
        ));
    }

    /**
     * Full free-text address when the sheet does not split street/city/province.
     *
     * @param  array<string, mixed>  $row
     */
    private function importHomeAddressFromRow(array $row): ?string
    {
        $direct = $this->cleanPersonalField($this->value($row, [
            'home_address',
            'address',
            'full_address',
            'complete_address',
            'residential_address',
            'current_address',
            'mailing_address',
            'home_full_address',
            'employee_address',
            'domicile',
            'present_address',
            'permanent_address',
            'primary_address',
            'contact_address',
            'local_address',
            'registered_address',
        ]));
        if ($direct !== null && $direct !== '') {
            return $direct;
        }

        return $this->cleanPersonalField($this->valueDemographic(
            $row,
            [],
            [
                'home_address',
                'full_address',
                'complete_address',
                'residential_address',
                'current_address',
                'mailing_address',
                'present_address',
                'permanent_address',
            ]
        ));
    }

    /**
     * Header-based lookup with optional substring match on normalized keys (handles odd export labels).
     * If an exact alias column exists but the cell is blank, that blank is returned (no substring override).
     *
     * @param  list<string>  $exactAliases
     * @param  list<string>  $normalizedKeyNeedles  Each must appear as substring of the normalized header key.
     */
    private function valueDemographic(array $row, array $exactAliases, array $normalizedKeyNeedles): mixed
    {
        foreach ($exactAliases as $alias) {
            $candidate = $this->normalizeHeaderKey($alias);
            foreach ($row as $key => $value) {
                if ($this->normalizeHeaderKey((string) $key) === $candidate) {
                    return $value;
                }
            }
        }

        foreach ($row as $key => $value) {
            $nk = $this->normalizeHeaderKey($this->sanitizeSpreadsheetHeaderKey((string) $key));
            if ($nk === '') {
                continue;
            }
            foreach ($normalizedKeyNeedles as $needle) {
                if ($needle !== '' && str_contains($nk, $needle)) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function normalizeHeaderKey(string $value): string
    {
        $value = trim($value);
        if ($value !== '' && ! preg_match('/\s/', $value) && preg_match('/[a-z][A-Z]/', $value)) {
            $value = Str::snake($value);
        }
        $v = Str::lower($value);
        $v = str_replace(['-', '(', ')', '/', '.'], ' ', $v);
        $v = preg_replace('/\s+/', '_', (string) $v);

        return trim((string) $v, '_');
    }

    /**
     * Strip BOM / invisible chars so "Gender" and ﻿"Gender" map the same.
     */
    private function sanitizeSpreadsheetHeaderKey(string $key): string
    {
        $key = preg_replace('/^\x{FEFF}/u', '', $key) ?? $key;
        $key = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $key) ?? $key;
        $key = trim($key);
        $key = trim($key, " \t\n\r\0\x0B*:#.");

        return $key;
    }

    private function normalizeHeadingRowData(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $label = $this->sanitizeSpreadsheetHeaderKey((string) $key);
            $normalized[$this->normalizeHeaderKey($label)] = $value;
        }

        return $normalized;
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof RichText) {
            $text = trim($value->getPlainText());

            return $text === '' ? null : $text;
        }
        if ($value instanceof \Stringable) {
            $value = $value->__toString();
        }
        if (! is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * Trim and collapse internal whitespace for optional personal fields (gender, civil_status, nationality).
     */
    private function cleanPersonalField(mixed $value): ?string
    {
        $text = $this->clean($value);
        if ($text === null) {
            return null;
        }
        $text = str_replace(["\xc2\xa0", "\xe2\x80\xaf"], ' ', $text);
        $collapsed = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return $collapsed === '' ? null : $collapsed;
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof RichText) {
            $value = trim($value->getPlainText());
            if ($value === '') {
                return null;
            }
        }
        if ($value instanceof Carbon) {
            return $value->toDateString();
        }
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($value))->toDateString();
        }
        if (is_numeric($value)) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }
        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Parse a cell value into MySQL-compatible time (H:i:s) for working_schedules.time_in / time_out.
     * Handles Excel time fractions, datetime serials, DateTime/Carbon instances, and plain time strings.
     */
    private function parseWorkingTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($value))->format('H:i:s');
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }
        }

        if (is_numeric($value)) {
            $n = (float) $value;
            if ($n < 0) {
                return null;
            }
            try {
                return ExcelDate::excelToDateTimeObject($n)->format('H:i:s');
            } catch (\Throwable) {
                // continue to string parsing
            }
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = $this->scrubTimeLikeString($value);

        $epochWall = $this->parseWallClockFromExcelEpochTimeString($normalized);
        if ($epochWall !== null) {
            return $epochWall;
        }

        $gmtUtcWall = $this->parseWallClockBeforeGmtOrUtc($normalized);
        if ($gmtUtcWall !== null) {
            return $gmtUtcWall;
        }

        $isoDateWall = $this->parseTimeAfterIsoOrSlashDate($normalized);
        if ($isoDateWall !== null) {
            return $isoDateWall;
        }

        if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?(\s*[AaPp][Mm])?$/', $normalized)) {
            try {
                return Carbon::parse($normalized)->format('H:i:s');
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse($normalized)->format('H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Collapse whitespace and trim; helps schedule names from Excel exports.
     */
    private function normalizeWorkingScheduleLabel(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = preg_replace('/\s+/u', ' ', trim((string) $value));

        return $text === '' ? null : $text;
    }

    /**
     * Map "Saturday, Sunday", "SAT|SUN", "sat sun", etc. to attendance day keys (sun…sat).
     *
     * @return list<string>
     */
    private function normalizeRestDayKeys(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/\s*[,;|\/]\s*|\s+and\s+|\s*&\s*|\s+-\s+|\s+/iu', $raw) ?: [];
        $keys = [];
        foreach ($parts as $part) {
            $token = trim((string) $part);
            if ($token === '') {
                continue;
            }
            $key = $this->mapRestDayTokenToScheduleKey($token);
            if ($key !== null && ! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return array_values(array_intersect(self::SCHEDULE_DAY_KEYS, $keys));
    }

    private function mapRestDayTokenToScheduleKey(string $token): ?string
    {
        $t = Str::lower(trim($token));
        $t = preg_replace('/[^a-z]+$/', '', $t) ?? $t;
        $t = preg_replace('/[^a-z]/', '', $t) ?? '';
        if ($t === '') {
            return null;
        }

        static $aliases = [
            'sunday' => 'sun',
            'monday' => 'mon',
            'tuesday' => 'tue',
            'wednesday' => 'wed',
            'thursday' => 'thu',
            'friday' => 'fri',
            'saturday' => 'sat',
            'sun' => 'sun',
            'mon' => 'mon',
            'tue' => 'tue',
            'wed' => 'wed',
            'thu' => 'thu',
            'fri' => 'fri',
            'sat' => 'sat',
            'thurs' => 'thu',
            'tues' => 'tue',
        ];

        return $aliases[$t] ?? null;
    }

    private function scrubTimeLikeString(string $value): string
    {
        $v = trim($value);
        $v = preg_replace('/\s+/', ' ', $v) ?? $v;

        return $v;
    }

    /**
     * Excel time-only cells often surface as "Sat Dec 30 1899 HH:MM:SS …" when stringified.
     * Take the clock portion literally so it matches what users see in the sheet.
     */
    private function parseWallClockFromExcelEpochTimeString(string $value): ?string
    {
        if (! preg_match('/\b1899\b|\bdec\s*30,?\s*1899\b/i', $value)) {
            return null;
        }
        if (! preg_match('/\b(\d{1,2}):(\d{2})(?::(\d{2}))?\b/', $value, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $i = (int) $m[2];
        $s = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 0;
        if ($h < 0 || $h > 23 || $i < 0 || $i > 59 || $s < 0 || $s > 59) {
            return null;
        }

        return sprintf('%02d:%02d:%02d', $h, $i, $s);
    }

    /**
     * JS / Excel string exports like "… 16:00:00 GMT+0800" — take the clock before GMT/UTC.
     */
    private function parseWallClockBeforeGmtOrUtc(string $value): ?string
    {
        if (! preg_match('/\bGMT\b|\bUTC\b/i', $value)) {
            return null;
        }
        if (! preg_match('/\b((?:\d{1,2}:\d{2}(?::\d{2})?)(?:\s*[AaPp][Mm])?)\s*(?:GMT|UTC)/i', $value, $m)) {
            return null;
        }
        try {
            return Carbon::parse(trim($m[1]))->format('H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Excel/CSV sometimes emits "1899-12-30 16:00:00" or "12/30/1899 4:30:00 PM" without our 1899 token branch.
     */
    private function parseTimeAfterIsoOrSlashDate(string $value): ?string
    {
        if (preg_match('/\d{4}-\d{2}-\d{2}[T ]\s*(\d{1,2}:\d{2}(?::\d{2})?)/', $value, $m)) {
            try {
                return Carbon::parse($m[1])->format('H:i:s');
            } catch (\Throwable) {
                return null;
            }
        }
        if (preg_match('#\d{1,2}/\d{1,2}/\d{4}\s+((?:\d{1,2}:\d{2}(?::\d{2})?)(?:\s*[AaPp][Mm])?)#', $value, $m)) {
            try {
                return Carbon::parse(trim($m[1]))->format('H:i:s');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Normalize spreadsheet “empty” contact cells (N/A, dash, etc.) before validation / PH phone parsing.
     */
    private function cleanContactImportCell(mixed $value): ?string
    {
        $t = $this->clean($value);
        if ($t === null) {
            return null;
        }
        $l = Str::lower($t);
        if (in_array($l, ['n/a', '#n/a', '#na', '-', '—', '--', 'none', 'null', '(none)', 'tbd', 'tba', 'no email', 'no phone', 'na'], true)) {
            return null;
        }

        return $t;
    }

    /**
     * Avoid storing "" for nullable unique columns (email / phone); Excel blanks often arrive as empty strings.
     */
    private function nullIfBlank(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \Stringable) {
            $value = $value->__toString();
        }
        if (! is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function parseDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = str_replace([',', 'PHP', 'php'], '', (string) $value);
        $normalized = trim($normalized);
        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    private function parseInt(mixed $value): ?int
    {
        $v = $this->clean($value);
        if ($v === null || ! is_numeric($v)) {
            return null;
        }

        return (int) $v;
    }

    private function parseBoolean(mixed $value, bool $default = true): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $v = Str::lower(trim((string) $value));
        if (in_array($v, ['1', 'true', 'yes', 'y', 'active'], true)) {
            return true;
        }
        if (in_array($v, ['0', 'false', 'no', 'n', 'inactive'], true)) {
            return false;
        }

        return $default;
    }

    /**
     * Turn spreadsheet ALL-CAPS or all-lowercase person names into natural title case (each word), UTF-8 aware.
     */
    private function normalizeImportedPersonName(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $value;
        }
        if (function_exists('mb_strtolower') && function_exists('mb_convert_case')) {
            $lower = mb_strtolower($trimmed, 'UTF-8');

            return mb_convert_case($lower, MB_CASE_TITLE, 'UTF-8');
        }

        return Str::title(Str::lower($trimmed));
    }

    private function splitName(?string $fullName): array
    {
        $name = trim((string) $fullName);
        if ($name === '') {
            return [null, null, null];
        }
        $parts = preg_split('/\s+/', $name) ?: [];
        if (count($parts) === 1) {
            return [$parts[0], null, null];
        }
        if (count($parts) === 2) {
            return [$parts[0], null, $parts[1]];
        }

        return [$parts[0], implode(' ', array_slice($parts, 1, -1)), $parts[count($parts) - 1]];
    }

    private function composeName(string $first, ?string $middle, string $last): string
    {
        $parts = [$first];
        if ($middle) {
            $parts[] = $middle;
        }
        $parts[] = $last;

        return trim(implode(' ', $parts));
    }

    private function normalizeEmploymentType(mixed $value): ?string
    {
        $v = Str::lower((string) $this->clean($value));
        if ($v === '') {
            return null;
        }
        $v = str_replace([' ', '-'], '_', $v);
        $map = [
            'full_time' => 'full_time',
            'fulltime' => 'full_time',
            'ft' => 'full_time',
            'permanent' => 'full_time',
            'regular' => 'full_time',
            'part_time' => 'part_time',
            'parttime' => 'part_time',
            'pt' => 'part_time',
            'contract' => 'contract',
            'contractual' => 'contract',
            'fixed_term' => 'contract',
            'fixedterm' => 'contract',
            'probation' => 'probationary',
            'probationary' => 'probationary',
            'casual' => 'part_time',
            'seasonal' => 'contract',
            'intern' => 'contract',
            'internship' => 'contract',
        ];

        return $map[$v] ?? str_replace('-', '_', $v);
    }

    private function normalizeEmploymentStatus(mixed $value): ?string
    {
        $raw = $this->clean($value);
        if ($raw === null || $raw === '') {
            return null;
        }

        $resolved = EmploymentStatus::tryFromStored($raw);
        if ($resolved !== null) {
            return $resolved->value;
        }

        $firstToken = preg_split('/[\s,;|\/—–-]+/u', $raw, 2)[0] ?? $raw;
        $firstToken = trim((string) $firstToken);
        if ($firstToken !== '' && $firstToken !== $raw) {
            $resolved = EmploymentStatus::tryFromStored($firstToken);
            if ($resolved !== null) {
                return $resolved->value;
            }
        }

        if (preg_match('/^([A-Za-z]+)/u', $raw, $m)) {
            $resolved = EmploymentStatus::tryFromStored($m[1]);
            if ($resolved !== null) {
                return $resolved->value;
            }
        }

        return null;
    }

    private function normalizeTaxRegime(mixed $value): string
    {
        $v = Str::lower((string) $this->clean($value));
        if ($v === '') {
            return 'standard_train';
        }

        // Accept common import labels and normalize.
        if (in_array($v, ['train', 'standard_train', 'standard train'], true)) {
            return 'standard_train';
        }

        return str_replace(' ', '_', $v);
    }

    private function normalizeWithholdingMethod(mixed $value): string
    {
        $v = Str::lower((string) $this->clean($value));
        if ($v === '') {
            return 'monthly';
        }

        if (in_array($v, ['monthly', 'month'], true)) {
            return 'monthly';
        }
        if (in_array($v, ['annualized', 'annual'], true)) {
            return 'annualized';
        }

        return $v;
    }

    /**
     * Normalize PH mobile numbers to +639XXXXXXXXX when recognizable.
     * Accepts inputs starting with +63, 63, 09, or 9.
     * If unrecognized or empty, returns null.
     */
    private function normalizePhoneNumber(mixed $value): ?string
    {
        $raw = $this->clean($value);
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '639') && strlen($digits) === 12) {
            return '+'.$digits;
        }
        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            return '+'.$digits;
        }
        if (str_starts_with($digits, '09') && strlen($digits) === 11) {
            return '+63'.substr($digits, 1);
        }
        if (str_starts_with($digits, '9') && strlen($digits) === 10) {
            return '+63'.$digits;
        }

        // Keep import tolerant: unknown/invalid formats become null (not blocking).
        return null;
    }

    private function resolveUniquePhoneOrNull(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }
        $exists = User::query()->where('phone_number', $phone)->exists();

        return $exists ? null : $phone;
    }

    private function buildUniqueUsername(string $first): string
    {
        // Username must always come from first *word* of first name (e.g. "Mike Nelson" -> "mike").
        $trimmed = trim($first);
        $parts = preg_split('/\s+/u', $trimmed) ?: [];
        $given = (string) ($parts[0] ?? '');
        $asciiGiven = Str::ascii($given);
        $base = Str::lower(preg_replace('/\s+/', '', $asciiGiven) ?? '');

        $base = preg_replace('/[^A-Za-z0-9._]/', '', $base) ?: 'employee';
        $candidate = $base;
        $counter = 1;
        while (User::query()->where('username', $candidate)->exists()) {
            $candidate = $base.$counter;
            $counter++;
        }

        return $candidate;
    }

    private function resolveSupervisorId(?string $value): ?int
    {
        if (! $value) {
            return null;
        }

        $supervisor = User::query()
            ->where(function ($q) use ($value) {
                $q->whereRaw('LOWER(name)=?', [Str::lower($value)])
                    ->orWhereRaw('LOWER(email)=?', [Str::lower($value)])
                    ->orWhereRaw('LOWER(employee_code)=?', [Str::lower($value)]);
            })
            ->first();

        return $supervisor?->id;
    }

    /**
     * Link to an existing {@see WorkingSchedule} only. Admin → Schedule stores templates in the `working_schedules`
     * table; this import path never inserts new schedule rows.
     *
     * When the sheet omits schedule name / times / rest days, or they do not match any row, assign the best existing
     * template (e.g. "Regular Day Shift").
     */
    private function resolveWorkingScheduleId(array $payload): ?int
    {
        $name = $this->nullIfBlank($payload['working_schedule'] ?? null);
        $restDays = is_array($payload['rest_days'] ?? null)
            ? array_values(array_intersect(self::SCHEDULE_DAY_KEYS, $payload['rest_days']))
            : [];

        if ($name && ! $this->looksLikeScheduleTimeRangeDescription($name)) {
            $id = $this->findWorkingScheduleIdByExactName($name);
            if ($id !== null) {
                return $id;
            }
            $id = $this->findWorkingScheduleIdByFuzzyTemplateName($name);
            if ($id !== null) {
                return $id;
            }
        }

        $timeIn = $this->nullIfBlank($payload['working_time_in'] ?? null);
        $timeOut = $this->nullIfBlank($payload['working_time_out'] ?? null);

        if ((! $timeIn || ! $timeOut) && $name && $this->looksLikeScheduleTimeRangeDescription($name)) {
            $parsed = $this->parseTimeRangeEndsFromDescription($name);
            if ($parsed) {
                [$timeIn, $timeOut] = $parsed;
            }
        }

        if ($timeIn && $timeOut) {
            $matched = $this->findExistingWorkingScheduleByClock($timeIn, $timeOut, $restDays);
            if ($matched) {
                return $matched->id;
            }
        }

        return $this->findPreferredRegularDayTemplateId();
    }

    private function findWorkingScheduleIdByExactName(string $name): ?int
    {
        $want = Str::lower(preg_replace('/\s+/u', ' ', trim($name)));
        if ($want === '') {
            return null;
        }

        if ($this->workingScheduleExactNameIndex === null) {
            $index = [];
            foreach (WorkingSchedule::query()->orderBy('id')->cursor() as $schedule) {
                $key = Str::lower(preg_replace('/\s+/u', ' ', trim((string) $schedule->name)));
                if ($key !== '' && ! isset($index[$key])) {
                    $index[$key] = (int) $schedule->id;
                }
            }
            $this->workingScheduleExactNameIndex = $index;
        }

        return $this->workingScheduleExactNameIndex[$want] ?? null;
    }

    /**
     * Match "Regular Day Shift (HQ)" etc. to an existing template using the same hints as clock-based ranking.
     */
    private function findWorkingScheduleIdByFuzzyTemplateName(string $name): ?int
    {
        $l = Str::lower(trim($name));
        if ($l === '') {
            return null;
        }

        foreach (self::IMPORT_SCHEDULE_NAME_PREFERENCE as $needle) {
            if ($needle === '' || ! str_contains($l, $needle)) {
                continue;
            }
            $found = WorkingSchedule::query()
                ->whereRaw('LOWER(name) LIKE ?', ['%'.$needle.'%'])
                ->orderBy('id')
                ->first();
            if ($found) {
                return (int) $found->id;
            }
        }

        return null;
    }

    private function findPreferredRegularDayTemplateId(): ?int
    {
        foreach (self::IMPORT_SCHEDULE_NAME_PREFERENCE as $needle) {
            if ($needle === '') {
                continue;
            }
            $found = WorkingSchedule::query()
                ->whereRaw('LOWER(name) LIKE ?', ['%'.$needle.'%'])
                ->orderBy('id')
                ->first();
            if ($found) {
                return (int) $found->id;
            }
        }

        $fallback = WorkingSchedule::query()
            ->where(function ($q): void {
                $q->whereRaw('LOWER(name) LIKE ?', ['%regular%'])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%day shift%'])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%office shift%']);
            })
            ->orderBy('id')
            ->first();

        if ($fallback) {
            return (int) $fallback->id;
        }

        return null;
    }

    private function normalizeImportedGender(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $k = Str::lower(preg_replace('/\s+/u', ' ', trim($value)));
        $k = preg_replace('/[^a-z]+$/', '', $k) ?? $k;
        $k = trim($k);

        if (in_array($k, ['m', 'male', 'man', 'men', 'masculine'], true)) {
            return 'Male';
        }
        if (in_array($k, ['f', 'female', 'woman', 'women', 'feminine'], true)) {
            return 'Female';
        }

        return $this->mbTitleCasePersonLabel($k);
    }

    private function normalizeImportedCivilStatus(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $raw = preg_replace('/\s+/u', ' ', trim($value));
        $compact = strtolower(preg_replace('/[^a-z]/', '', $raw) ?? '');

        static $synonyms = [
            'single' => 'Single',
            'unmarried' => 'Single',
            'nevermarried' => 'Single',
            'married' => 'Married',
            'lawfullymarried' => 'Married',
            'widowed' => 'Widowed',
            'widower' => 'Widowed',
            'widow' => 'Widowed',
            'separated' => 'Separated',
            'legallyseparated' => 'Separated',
            'legalseparation' => 'Separated',
            'divorced' => 'Divorced',
            'annulled' => 'Annulled',
            'annulment' => 'Annulled',
            'commonlaw' => 'Common-law',
            'commonlawmarriage' => 'Common-law',
            'livein' => 'Common-law',
            'liveinpartner' => 'Common-law',
        ];

        if (isset($synonyms[$compact])) {
            return $synonyms[$compact];
        }

        return $this->mbTitleCasePersonLabel(Str::lower($raw));
    }

    private function normalizeImportedNationality(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $raw = preg_replace('/\s+/u', ' ', trim($value));
        $compact = strtolower(preg_replace('/[^a-z]/', '', $raw) ?? '');

        static $synonyms = [
            'filipino' => 'Filipino',
            'filipina' => 'Filipino',
            'philippines' => 'Filipino',
            'philippine' => 'Filipino',
            'pinoy' => 'Filipino',
            'pinay' => 'Filipino',
            'ph' => 'Filipino',
            'american' => 'American',
            'usa' => 'American',
            'unitedstates' => 'American',
            'us' => 'American',
            'australian' => 'Australian',
            'british' => 'British',
            'uk' => 'British',
            'canadian' => 'Canadian',
            'chinese' => 'Chinese',
            'indian' => 'Indian',
            'indonesian' => 'Indonesian',
            'japanese' => 'Japanese',
            'korean' => 'Korean',
            'malaysian' => 'Malaysian',
            'singaporean' => 'Singaporean',
            'thai' => 'Thai',
            'vietnamese' => 'Vietnamese',
            'other' => 'Other',
        ];

        if (isset($synonyms[$compact])) {
            return $synonyms[$compact];
        }

        return $this->mbTitleCasePersonLabel(Str::lower($raw));
    }

    /**
     * Title-case a demographic label using UTF-8 when mbstring is available (e.g. "FILIPINO" → "Filipino").
     */
    private function mbTitleCasePersonLabel(string $value): string
    {
        $v = trim($value);
        if ($v === '') {
            return $value;
        }
        if (function_exists('mb_strtolower') && function_exists('mb_convert_case')) {
            $lower = mb_strtolower($v, 'UTF-8');

            return mb_convert_case($lower, MB_CASE_TITLE, 'UTF-8');
        }

        return Str::title($v);
    }

    /**
     * True when the cell looks like "8:00 AM - 5:00 PM" rather than a saved template name.
     */
    private function looksLikeScheduleTimeRangeDescription(?string $name): bool
    {
        if ($name === null || trim($name) === '') {
            return false;
        }
        if (! preg_match('/\d{1,2}\s*:\s*\d{2}/', $name)) {
            return false;
        }

        return (bool) preg_match('/\s*(?:-|–|—|to)\s*/i', $name);
    }

    /**
     * @return array{0:string,1:string}|null H:i:s pair
     */
    private function parseTimeRangeEndsFromDescription(string $name): ?array
    {
        $parts = preg_split('/\s*(?:-|–|—|to)\s*/i', $name, 2);
        if ($parts === false || count($parts) < 2) {
            return null;
        }
        try {
            $in = Carbon::parse(trim($parts[0]))->format('H:i:s');
            $out = Carbon::parse(trim($parts[1]))->format('H:i:s');

            return [$in, $out];
        } catch (\Throwable) {
            return null;
        }
    }

    private function canonicalTimeString(string $value): string
    {
        try {
            return Carbon::parse('2000-01-01 '.$value)->format('H:i:s');
        } catch (\Throwable) {
            return trim($value);
        }
    }

    private function restDaysSignature(array $keys): string
    {
        $k = array_values(array_intersect(self::SCHEDULE_DAY_KEYS, $keys));
        sort($k);

        return implode(',', $k);
    }

    private function importScheduleNameRank(string $scheduleName): int
    {
        $n = Str::lower($scheduleName);
        foreach (self::IMPORT_SCHEDULE_NAME_PREFERENCE as $index => $needle) {
            if (str_contains($n, $needle)) {
                return $index;
            }
        }

        return 100 + abs(crc32($n) % 900);
    }

    private function findExistingWorkingScheduleByClock(string $timeIn, string $timeOut, array $restDayKeys): ?WorkingSchedule
    {
        $wantIn = $this->canonicalTimeString($timeIn);
        $wantOut = $this->canonicalTimeString($timeOut);
        $wantRest = $this->restDaysSignature($restDayKeys);

        $byClock = WorkingSchedule::query()->get()->filter(function (WorkingSchedule $s) use ($wantIn, $wantOut) {
            return $this->canonicalTimeString((string) $s->time_in) === $wantIn
                && $this->canonicalTimeString((string) $s->time_out) === $wantOut;
        });

        if ($byClock->isEmpty()) {
            return null;
        }

        if ($wantRest !== '') {
            $withRest = $byClock->filter(function (WorkingSchedule $s) use ($wantRest) {
                return $this->restDaysSignature($s->rest_days ?? []) === $wantRest;
            });
            if ($withRest->isNotEmpty()) {
                $byClock = $withRest;
            }
        }

        return $byClock->sortBy(function (WorkingSchedule $s) {
            return [$this->importScheduleNameRank($s->name), $s->id];
        })->first();
    }

    private function resolvePayCycleId(?string $value, ?int $companyId): ?int
    {
        if (! $value) {
            return null;
        }
        $v = Str::lower(trim($value));
        $code = null;
        if (in_array($v, ['both', 'semi monthly', 'semi-monthly'], true)) {
            $code = PayCycle::CODE_SEMI_MONTHLY;
        } elseif (in_array($v, ['30th', 'monthly'], true)) {
            $code = PayCycle::CODE_MONTHLY;
        }
        if (! $code) {
            return null;
        }

        $query = PayCycle::query()->where('code', $code)->where('is_active', true);
        if ($companyId) {
            $specific = (clone $query)->where('company_id', $companyId)->first();
            if ($specific) {
                return $specific->id;
            }
        }

        return $query->whereNull('company_id')->value('id') ?: $query->value('id');
    }
}
