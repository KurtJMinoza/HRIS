@php
  $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
  $earnLines = is_array($summary['payslip_earning_lines'] ?? null) ? array_values($summary['payslip_earning_lines']) : [];
  $dailyEarnLines = is_array($summary['daily_computation_earning_lines'] ?? null) ? array_values($summary['daily_computation_earning_lines']) : [];
  $govDedLines = is_array($summary['payslip_deduction_lines'] ?? null) ? array_values($summary['payslip_deduction_lines']) : [];
  $customDedLines = is_array($summary['payslip_custom_deduction_lines'] ?? null) ? array_values($summary['payslip_custom_deduction_lines']) : [];
  $govIds = isset($govIds) && $govIds !== null ? $govIds : $employee->governmentIds;
  $govTin = trim((string) ($govIds?->tin_number ?? '')) !== '' ? trim((string) $govIds->tin_number) : 'Not set';
  $govSss = trim((string) ($govIds?->sss_number ?? '')) !== '' ? trim((string) $govIds->sss_number) : 'Not set';
  $govPhilhealth = trim((string) ($govIds?->philhealth_number ?? '')) !== '' ? trim((string) $govIds->philhealth_number) : 'Not set';
  $govPagibig = trim((string) ($govIds?->pagibig_number ?? '')) !== '' ? trim((string) $govIds->pagibig_number) : 'Not set';
  $govLine = "{$govTin} — {$govSss} — {$govPhilhealth} — {$govPagibig} —";

  $formatMoney = static function ($value): string {
    return 'PHP '.number_format((float) ($value ?? 0), 2);
  };

  /**
   * Units are pre-normalized in PayslipService via formatUnitsAndAmount().
   * Blade only displays the normalized value to avoid divergence from preview.
   */
  $formatUnits = static function (array $line): string {
    $unitsRaw = trim((string) ($line['units'] ?? ''));
    if ($unitsRaw !== '') {
      return $unitsRaw;
    }
    return '—';
  };

  $formatDate = static function ($value): string {
    if (empty($value)) {
      return '—';
    }
    try {
      return \Illuminate\Support\Carbon::parse($value)->format('M d, Y');
    } catch (\Throwable) {
      return (string) $value;
    }
  };

  $periodLabel = $formatDate($payslip->pay_period_start).' - '.$formatDate($payslip->pay_period_end);
  $payDate = $payslip->pay_date ?? $payslip->reference_date ?? null;
  $dailyRate = (float) ($summary['daily_rate'] ?? ($snapshot['daily_rate'] ?? 0));
  $statusLabel = strtolower(trim((string) ($payslip->status ?? ''))) === 'finalized' ? 'Finalized' : 'Draft';
  $compBreakdown = is_array($summary['compensation_breakdown'] ?? null) ? $summary['compensation_breakdown'] : [];
  $payrollModule = strtolower(trim((string) (
    $compBreakdown['payroll_module']
    ?? $summary['payroll_module']
    ?? ($snapshot['payroll_module'] ?? '')
  )));
  $isExecomPayroll = $payrollModule === 'execom';

  if (! $isExecomPayroll && count($dailyEarnLines) === 0) {
    $fallback = [];
    $regularPay = (float) ($summary['basic_pay_this_period'] ?? ($summary['total_pay'] ?? 0));
    $attendancePremium = (float) ($summary['attendance_premium_pay_this_period'] ?? 0);
    if ($regularPay > 0) {
      $fallback[] = ['label' => 'Regular pay', 'amount' => $regularPay];
    }
    if ($attendancePremium > 0) {
      $fallback[] = ['label' => 'Attendance premiums (OT/ND/Holiday)', 'amount' => $attendancePremium];
    }
    $dailyEarnLines = $fallback;
  }

  $logoLocalPath = null;
  $logoRaw = is_string($company?->logo ?? null) ? trim((string) $company->logo) : '';
  if (! $isExecomPayroll && $logoRaw !== '') {
    $normalized = ltrim($logoRaw, '/');
    if (str_starts_with($normalized, 'storage/')) {
      $normalized = ltrim(substr($normalized, strlen('storage/')), '/');
    }
    $candidate = storage_path('app/public/'.$normalized);
    if (is_file($candidate)) {
      $logoLocalPath = $candidate;
    }
  }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <style>
    @page { size: A4 portrait; margin: 10mm; }
    * { box-sizing: border-box; }
    html, body {
      margin: 0;
      padding: 0;
      color: #0a0a0a;
      background: #ffffff;
      font-family: DejaVu Sans, Arial, sans-serif;
      font-size: 10px;
      line-height: 1.35;
    }
    .sheet {
      width: 100%;
      margin: 0;
      padding: 0;
      page-break-inside: avoid;
      break-inside: avoid;
    }
    .header {
      width: 100%;
      padding-bottom: 8px;
      margin-bottom: 8px;
    }
    .header-table {
      width: 100%;
      border-collapse: collapse;
    }
    .header-table td {
      vertical-align: top;
    }
    .left {
      width: 72%;
    }
    .right {
      width: 28%;
      text-align: right;
    }
    .logo-wrap {
      width: 48px;
      height: 48px;
      border-radius: 999px;
      overflow: hidden;
      display: inline-block;
      vertical-align: top;
      margin-right: 8px;
      background: #fff;
    }
    .logo {
      width: 100%;
      height: 100%;
      object-fit: contain;
      display: block;
    }
    .company {
      display: inline-block;
      vertical-align: top;
      max-width: 460px;
    }
    .company h1 {
      margin: 0 0 3px;
      font-size: 15px;
      line-height: 1.2;
    }
    .muted {
      color: #64748b;
      font-size: 9px;
    }
    .badge {
      display: inline-block;
      border: 1px solid #dbeafe;
      background: #eff6ff;
      color: #1e3a8a;
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 8px;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }
    .meta {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 8px;
      border-radius: 8px;
      overflow: hidden;
    }
    .meta td {
      width: 25%;
      padding: 6px 8px;
      vertical-align: top;
    }
    .k {
      font-size: 8px;
      color: #64748b;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      margin-bottom: 2px;
    }
    .v {
      font-size: 10px;
      font-weight: 600;
      color: #0a0a0a;
      font-variant-numeric: tabular-nums;
    }
    .gov-grid {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
    }
    .gov-grid td {
      padding: 1px 0;
      vertical-align: top;
      font-size: 10px;
      line-height: 1.25;
      color: #0a0a0a;
    }
    .gov-grid .gov-key {
      width: 18%;
      color: #0a0a0a;
      font-size: 8px;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      font-weight: 600;
      padding-right: 6px;
      white-space: nowrap;
    }
    .gov-grid .gov-val {
      width: 32%;
      color: #0a0a0a;
      font-weight: 600;
      font-variant-numeric: tabular-nums;
      padding-right: 12px;
    }
    .section {
      border-radius: 8px;
      margin-bottom: 8px;
      overflow: hidden;
      page-break-inside: avoid;
      break-inside: avoid;
    }
    .section-title {
      background: #f8fafc;
      padding: 6px 8px;
      font-size: 9px;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      font-weight: 700;
    }
    .employee-grid {
      width: 100%;
      border-collapse: collapse;
    }
    .employee-grid td {
      width: 25%;
      padding: 6px 8px;
      vertical-align: top;
    }
    .tables {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
      margin-bottom: 8px;
    }
    .tables > tbody > tr > td {
      width: 50%;
      vertical-align: top;
      padding-right: 6px;
    }
    .tables > tbody > tr > td:last-child {
      padding-right: 0;
      padding-left: 6px;
    }
    .block-title {
      margin: 0 0 4px;
      font-size: 9px;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #0a0a0a;
    }
    .lines {
      width: 100%;
      border-collapse: collapse;
      border-radius: 8px;
      overflow: hidden;
      page-break-inside: avoid;
      break-inside: avoid;
    }
    .lines thead td {
      background: #ffffff;
      padding: 6px 8px;
      font-size: 8px;
      font-weight: 700;
      color: #0a0a0a;
      text-transform: uppercase;
      letter-spacing: 0.06em;
    }
    .lines tbody td {
      padding: 5px 8px;
      font-size: 9px;
      color: #0a0a0a;
      vertical-align: top;
    }
    .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .units { text-align: center; color: #64748b; white-space: nowrap; }
    .total td {
      background: #f8fafc;
      font-weight: 700;
    }
    .net {
      border-radius: 10px;
      background: #0a0a0a;
      color: #ffffff;
      text-align: center;
      padding: 8px 10px;
      page-break-inside: avoid;
      break-inside: avoid;
    }
    .net .k {
      color: rgba(255,255,255,0.82);
      margin-bottom: 3px;
    }
    .net .value {
      font-size: 18px;
      line-height: 1.1;
      font-weight: 700;
      margin-bottom: 3px;
      font-variant-numeric: tabular-nums;
    }
    .net .period {
      font-size: 9px;
      color: rgba(255,255,255,0.82);
    }
  </style>
</head>
<body>
  <div class="sheet">
    <div class="header">
      <table class="header-table">
        <tr>
          <td class="left">
            @if($logoLocalPath)
              <span class="logo-wrap"><img src="{{ $logoLocalPath }}" class="logo" alt=""></span>
            @endif
            <div class="company">
              <h1>{{ $isExecomPayroll ? 'Execom' : ($company?->name ?? 'Company') }}</h1>
              @if(! $isExecomPayroll)
                <div class="muted">{{ trim((string) ($company?->address ?? '')) !== '' ? trim((string) $company->address) : '—' }}</div>
                <div class="muted">TIN: {{ trim((string) ($company?->tin ?? '')) !== '' ? trim((string) $company->tin) : '—' }}</div>
              @endif
            </div>
          </td>
          <td class="right">
            <span class="badge">Official Payslip</span>
          </td>
        </tr>
      </table>
    </div>

    <table class="meta">
      <tr>
        <td>
          <div class="k">Pay Period</div>
          <div class="v">{{ $periodLabel }}</div>
        </td>
        <td>
          <div class="k">Pay Date</div>
          <div class="v">{{ $formatDate($payDate) }}</div>
        </td>
        <td>
          <div class="k">Status</div>
          <div class="v">{{ $statusLabel }}</div>
        </td>
        <td>
          <div class="k">Daily Rate</div>
          <div class="v">{{ $formatMoney($dailyRate) }}</div>
        </td>
      </tr>
    </table>

    <div class="section">
      <div class="section-title">Employee Information</div>
      <table class="employee-grid">
        <tr>
          <td><div class="k">Name</div><div class="v">{{ $employee->display_name ?? $employee->name ?? '—' }}</div></td>
          <td><div class="k">Employee ID</div><div class="v">{{ $employee->employee_code ?? '—' }}</div></td>
          <td><div class="k">Department</div><div class="v">{{ $employee->departmentRelation?->name ?? $employee->department ?? '—' }}</div></td>
          <td><div class="k">Position</div><div class="v">{{ trim((string) ($employee->position ?? '')) !== '' ? trim((string) $employee->position) : '—' }}</div></td>
        </tr>
        <tr>
          <td><div class="k">Employment Status</div><div class="v">{{ (isset($employmentStatusLabel) && trim((string) $employmentStatusLabel) !== '') ? trim((string) $employmentStatusLabel) : '—' }}</div></td>
          <td><div class="k">Employment Type</div><div class="v">{{ (isset($employmentTypeLabel) && trim((string) $employmentTypeLabel) !== '') ? trim((string) $employmentTypeLabel) : '—' }}</div></td>
          <td colspan="2">
            <div class="k">Government IDs</div>
            <table class="gov-grid" role="presentation">
              <tr>
                <td class="gov-key">TIN</td>
                <td class="gov-val">{{ $govTin }}</td>
                <td class="gov-key">PhilHealth</td>
                <td class="gov-val">{{ $govPhilhealth }}</td>
              </tr>
              <tr>
                <td class="gov-key">SSS</td>
                <td class="gov-val">{{ $govSss }}</td>
                <td class="gov-key">Pag-IBIG</td>
                <td class="gov-val">{{ $govPagibig }}</td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    </div>

    <table class="tables">
      <tr>
        <td>
          <div class="block-title">Earnings</div>
          <table class="lines">
            <thead>
              <tr>
                <td>Description</td>
                <td class="units" style="width:60px;">Units</td>
                <td class="num" style="width:92px;">Amount</td>
              </tr>
            </thead>
            <tbody>
              @if($isExecomPayroll)
                @foreach($earnLines as $line)
                  <tr>
                    <td>{{ $line['label'] ?? 'Earning' }}</td>
                    <td class="units">{{ $formatUnits($line) }}</td>
                    <td class="num">{{ $formatMoney($line['amount'] ?? 0) }}</td>
                  </tr>
                @endforeach
              @else
                @foreach($dailyEarnLines as $line)
                  <tr>
                    <td>{{ strtolower(trim((string) ($line['label'] ?? ''))) === 'holiday premium' ? 'Holiday premium' : ($line['label'] ?? 'Daily computation earning') }}</td>
                    <td class="units">{{ $formatUnits($line) }}</td>
                    <td class="num">{{ $formatMoney($line['amount'] ?? 0) }}</td>
                  </tr>
                @endforeach
                @foreach($earnLines as $line)
                  <tr>
                    <td>{{ $line['label'] ?? 'Earning' }}</td>
                    <td class="units">{{ $formatUnits($line) }}</td>
                    <td class="num">{{ $formatMoney($line['amount'] ?? 0) }}</td>
                  </tr>
                @endforeach
              @endif
              @if(count($dailyEarnLines) === 0 && count($earnLines) === 0)
                <tr><td>No earnings computed.</td><td class="units">—</td><td class="num">—</td></tr>
              @endif
              <tr class="total"><td>Total Gross Earnings</td><td class="units"></td><td class="num">{{ $formatMoney($payslip->gross_pay) }}</td></tr>
            </tbody>
          </table>
        </td>
        <td>
          <div class="block-title">Deductions</div>
          <table class="lines">
            <thead>
              <tr>
                <td>Description</td>
                <td class="num" style="width:92px;">Amount</td>
              </tr>
            </thead>
            <tbody>
              @foreach($govDedLines as $line)
                <tr>
                  <td>
                    {{ $line['label'] ?? 'Deduction' }}
                    @if(!empty($line['note']) || !empty($line['exemption_reason']))
                      <div style="margin-top:2px;font-size:8px;color:#64748b;">
                        {{ $line['note'] ?? 'Government deduction exempted' }}
                        @if(!empty($line['exemption_reason']))
                          — {{ $line['exemption_reason'] }}
                        @endif
                      </div>
                    @endif
                  </td>
                  <td class="num">{{ $formatMoney($line['amount'] ?? 0) }}</td>
                </tr>
              @endforeach
              @foreach($customDedLines as $line)
                <tr>
                  <td>{{ $line['label'] ?? 'Custom deduction' }}</td>
                  <td class="num">{{ $formatMoney($line['amount'] ?? 0) }}</td>
                </tr>
              @endforeach
              @if(count($govDedLines) === 0 && count($customDedLines) === 0)
                <tr><td>No deductions computed.</td><td class="num">—</td></tr>
              @endif
              <tr class="total"><td>Total Deductions</td><td class="num">{{ $formatMoney($payslip->total_deductions) }}</td></tr>
            </tbody>
          </table>
        </td>
      </tr>
    </table>

    <div class="net">
      <div class="k">Net Take-Home Pay</div>
      <div class="value">{{ $formatMoney($payslip->net_pay) }}</div>
      <div class="period">For the period {{ $periodLabel }}</div>
    </div>
  </div>
</body>
</html>
