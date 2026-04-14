@php
  $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
  $earnLines = is_array($summary['payslip_earning_lines'] ?? null) ? array_values($summary['payslip_earning_lines']) : [];
  $dailyEarnLines = is_array($summary['daily_computation_earning_lines'] ?? null) ? array_values($summary['daily_computation_earning_lines']) : [];
  $govDedLines = is_array($summary['payslip_deduction_lines'] ?? null) ? array_values($summary['payslip_deduction_lines']) : [];
  $customDedLines = is_array($summary['payslip_custom_deduction_lines'] ?? null) ? array_values($summary['payslip_custom_deduction_lines']) : [];
  $hasWithholdingLine = collect(array_merge($govDedLines, $customDedLines))
    ->contains(function ($line) {
      $key = strtolower((string) ($line['key'] ?? ''));
      $label = strtolower((string) ($line['label'] ?? ''));
      return str_contains($key, 'withholding') || str_contains($label, 'withholding');
    });
  $govIds = isset($govIds) && $govIds !== null ? $govIds : $employee->governmentIds;
  $logoRaw = $company?->logo;
  $logoPath = null;
  if (is_string($logoRaw) && trim($logoRaw) !== '') {
    $normalized = ltrim(trim($logoRaw), '/');
    if (str_starts_with($normalized, 'storage/')) {
      $normalized = ltrim(substr($normalized, strlen('storage/')), '/');
    }
    $segments = array_filter(explode('/', $normalized), fn ($s) => $s !== '');
    $logoPath = '/api/media/public/'.implode('/', array_map('rawurlencode', $segments));
  }

  $formatMoney = static function ($value): string {
    return 'PHP '.number_format((float) ($value ?? 0), 2);
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
  $displayDailyEarnLines = $dailyEarnLines;
  if (count($displayDailyEarnLines) === 0) {
      $fallback = [];
      $regularPay = (float) ($summary['basic_pay_this_period'] ?? ($summary['total_pay'] ?? 0));
      $attendancePremium = (float) ($summary['attendance_premium_pay_this_period'] ?? 0);
      if ($regularPay > 0) {
          $fallback[] = ['label' => 'Regular pay', 'amount' => $regularPay];
      }
      if ($attendancePremium > 0) {
          $fallback[] = ['label' => 'Attendance premiums (OT/ND/Holiday)', 'amount' => $attendancePremium];
      }
      $displayDailyEarnLines = $fallback;
  }

  $payDate = $payslip->pay_date ?? $payslip->reference_date ?? null;
  $dailyRate = $summary['daily_rate'] ?? ($snapshot['daily_rate'] ?? 0);
  $statusLabel = strtolower(trim((string) ($payslip->status ?? ''))) === 'finalized' ? 'Finalized' : 'Draft';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <style>
    @page { size: A4 portrait; margin: 12mm 10mm; }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body {
      font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
      color: #0A0A0A;
      background: #fff;
      font-size: 9px;
      line-height: 1.28;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }

    .sheet {
      border: 1px solid #e2e8f0;
      /* mPDF stability: avoid border-radius + overflow clipping on outer wrapper */
      border-radius: 0;
      overflow: visible;
      background: #fff;
      /* IMPORTANT (mPDF): do NOT prevent page breaks on the outer wrapper.
         The sheet content is taller than a single A4 page; forcing "avoid" here can make mPDF
         endlessly push the whole block to the next page, producing hundreds of blank pages. */
    }

    /* ── Header ── */
    .header {
      border-bottom: 1px solid #e2e8f0;
      padding: 9px 12px 8px;
      page-break-inside: avoid;
    }
    .header-table { width: 100%; border-collapse: collapse; }
    .header-table td { vertical-align: top; }
    .header-left { width: 62%; }
    .header-right { width: 38%; text-align: right; }

    .brand-table { width: 100%; border-collapse: collapse; }
    .brand-table td { vertical-align: top; }
    .logo-cell { width: 56px; padding-right: 10px; }
    .logo {
      width: 52px; height: 52px;
      border-radius: 999px;
      border: 1px solid #cbd5e1;
      object-fit: contain;
      background: #fff;
      display: block;
    }
    .logo-placeholder {
      width: 52px; height: 52px;
      border-radius: 999px;
      border: 1px solid #cbd5e1;
      background: #f8fafc;
    }
    .company-name {
      font-size: 15px;
      font-weight: 700;
      line-height: 1.15;
      color: #0A0A0A;
      margin-bottom: 3px;
    }
    .meta-label {
      font-size: 7.2px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #64748b;
      line-height: 1.3;
    }
    .meta-value {
      font-size: 8.4px;
      color: #0A0A0A;
      line-height: 1.3;
      margin-bottom: 3px;
    }

    .badge {
      display: inline-block;
      border: 1px solid #bae6fd;
      background: #f0f9ff;
      color: #0c4a6e;
      border-radius: 999px;
      padding: 3px 10px;
      font-size: 7px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.14em;
    }
    /* ── Content area ── */
    .content { padding: 8px 12px 6px; }
    .meta-bar {
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      background: #f8fafc;
      padding: 7px 10px;
      margin-bottom: 7px;
    }
    .meta-bar-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    .meta-bar-table td { width: 25%; vertical-align: top; padding-right: 8px; }
    .meta-bar-label {
      font-size: 7px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #64748b;
    }
    .meta-bar-value {
      font-size: 8.8px;
      font-weight: 600;
      margin-top: 2px;
      color: #0A0A0A;
      font-variant-numeric: tabular-nums;
    }

    /* ── Section cards (Employee, Gov IDs) ── */
    .section-card {
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      padding: 7px 9px;
      margin-bottom: 7px;
      background: #fafbfc;
      /* mPDF pagination: allow this to break when needed */
    }
    .section-title {
      font-size: 7.5px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      color: rgba(10,10,10,0.55);
      margin-bottom: 6px;
    }
    .fields-grid { width: 100%; border-collapse: collapse; }
    .fields-grid td { vertical-align: top; padding-right: 8px; padding-bottom: 2px; }
    .field-label {
      font-size: 7px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #64748b;
      line-height: 1.4;
    }
    .field-value {
      font-size: 8.4px;
      font-weight: 500;
      color: #0A0A0A;
      line-height: 1.3;
      margin-top: 1px;
    }

    /* ── Earnings / Deductions tables ── */
    .tables-row { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin-top: 2px; }
    .tables-row > tbody > tr > td { vertical-align: top; width: 50%; }

    .table-card {
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      /* mPDF stability: do not clip table content */
      overflow: visible;
      /* CRITICAL (mPDF): tables can be taller than one page; do NOT forbid page breaks here,
         or mPDF may push the whole card repeatedly and emit hundreds of blank pages. */
    }
    .table-head-earn {
      background: #f0fdf4;
      border-bottom: 1px solid #e2e8f0;
      border-left: 3px solid #16a34a;
      padding: 6px 8px;
      font-size: 7.5px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #14532d;
    }
    .table-head-ded {
      background: #fef2f2;
      border-bottom: 1px solid #e2e8f0;
      border-left: 3px solid #dc2626;
      padding: 6px 8px;
      font-size: 7.5px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #450a0a;
    }

    table.lines { width: 100%; border-collapse: collapse; }
    table.lines thead td {
      border-bottom: 1px solid #e2e8f0;
      padding: 5px 8px;
      font-size: 7px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: rgba(10,10,10,0.45);
      background: #fafbfc;
    }
    table.lines tbody td {
      border-bottom: 1px solid #f1f5f9;
      padding: 4px 8px;
      font-size: 8px;
      line-height: 1.25;
      vertical-align: top;
      color: rgba(10,10,10,0.8);
    }
    table.lines tbody tr:last-child td { border-bottom: 0; }
    .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .muted { color: #64748b; }
    .total-row td {
      border-top: 1px solid #dcfce7 !important;
      border-bottom: 0 !important;
      background: #f0fdf4;
      font-weight: 700;
      font-size: 8.8px;
      padding: 5px 8px;
      color: #0A0A0A;
    }
    .total-row.ded td {
      border-top: 1px solid #fee2e2 !important;
      background: #fef2f2;
    }
    .holiday-note {
      font-size: 6.4px;
      line-height: 1.15;
      color: #94a3b8;
      margin-bottom: 1px;
    }

    /* ── Net Pay Hero ── */
    .net-hero {
      border-radius: 12px;
      background: #0A0A0A;
      color: #fff;
      text-align: center;
      padding: 10px 12px;
      margin: 6px auto 0;
      width: 100%;
      max-width: 100%;
      /* allow page breaks around this block */
    }
    .net-hero-label {
      font-size: 7.5px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.2em;
      opacity: 0.8;
    }
    .net-hero-value {
      font-size: 22px;
      font-weight: 700;
      line-height: 1.1;
      margin: 4px 0 4px;
      font-variant-numeric: tabular-nums;
    }
    .net-hero-period {
      font-size: 7.5px;
      opacity: 0.7;
      line-height: 1.3;
    }

    .foot-note {
      text-align: center;
      margin-top: 6px;
      font-size: 6.5px;
      color: #6b7280;
      line-height: 1.3;
    }
  </style>
</head>
<body>
  <div class="sheet">
    {{-- ═══ HEADER ═══ --}}
    <div class="header">
      <table class="header-table">
        <tr>
          <td class="header-left">
            <table class="brand-table">
              <tr>
                <td class="logo-cell">
                  @if($logoPath)
                    <img src="{{ $logoPath }}" alt="" class="logo" />
                  @else
                    <div class="logo-placeholder"></div>
                  @endif
                </td>
                <td>
                  <div class="company-name">{{ $company?->name ?? 'Company' }}</div>
                  <div class="meta-label">Company Address</div>
                  <div class="meta-value">{{ ($company?->address && trim((string) $company->address) !== '') ? trim((string) $company->address) : '—' }}</div>
                  <div class="meta-label">Company TIN</div>
                  <div class="meta-value" style="margin-bottom:0;">{{ ($company?->tin && trim((string) $company->tin) !== '') ? trim((string) $company->tin) : '—' }}</div>
                </td>
              </tr>
            </table>
          </td>
          <td class="header-right">
            <span class="badge">Official Payslip</span>
          </td>
        </tr>
      </table>
    </div>

    {{-- ═══ CONTENT ═══ --}}
    <div class="content">
      <div class="meta-bar">
        <table class="meta-bar-table">
          <tr>
            <td>
              <div class="meta-bar-label">Pay Cycle</div>
              <div class="meta-bar-value">{{ $periodLabel }}</div>
            </td>
            <td>
              <div class="meta-bar-label">Pay Date</div>
              <div class="meta-bar-value">{{ $formatDate($payDate) }}</div>
            </td>
            <td>
              <div class="meta-bar-label">Status</div>
              <div class="meta-bar-value">{{ $statusLabel }}</div>
            </td>
            <td style="padding-right:0;">
              <div class="meta-bar-label">Daily Rate</div>
              <div class="meta-bar-value">{{ $formatMoney($dailyRate) }}</div>
            </td>
          </tr>
        </table>
      </div>

      {{-- Employee details --}}
      <div class="section-card">
        <div class="section-title">Employee Information</div>
        <table class="fields-grid">
          <tr>
            <td style="width:20%;">
              <div class="field-label">Name</div>
              <div class="field-value">{{ $employee->name ?? '—' }}</div>
            </td>
            <td style="width:14%;">
              <div class="field-label">Employee ID</div>
              <div class="field-value">{{ $employee->employee_code ?? '—' }}</div>
            </td>
            <td style="width:16%;">
              <div class="field-label">Department</div>
              <div class="field-value">{{ $employee->departmentRelation?->name ?? $employee->department ?? '—' }}</div>
            </td>
            <td style="width:13%;">
              <div class="field-label">Position</div>
              <div class="field-value">{{ trim((string) ($employee->position ?? '')) !== '' ? trim((string) $employee->position) : '—' }}</div>
            </td>
            <td style="width:12%;">
              <div class="field-label">Employment Status</div>
              <div class="field-value">{{ (isset($employmentStatusLabel) && is_string($employmentStatusLabel) && trim($employmentStatusLabel) !== '') ? trim($employmentStatusLabel) : '—' }}</div>
            </td>
            <td style="width:8%;">
              <div class="field-label">TIN</div>
              <div class="field-value">{{ $govIds?->tin_number ?? 'Not set' }}</div>
            </td>
            <td style="width:8%;">
              <div class="field-label">SSS</div>
              <div class="field-value">{{ $govIds?->sss_number ?? 'Not set' }}</div>
            </td>
            <td style="width:9%;">
              <div class="field-label">PhilHealth</div>
              <div class="field-value">{{ $govIds?->philhealth_number ?? 'Not set' }}</div>
            </td>
            <td style="width:10%;padding-right:0;">
              <div class="field-label">Pag-IBIG</div>
              <div class="field-value">{{ $govIds?->pagibig_number ?? 'Not set' }}</div>
            </td>
          </tr>
        </table>
      </div>

      {{-- Earnings + Deductions side by side --}}
      <table class="tables-row">
        <tr>
          <td>
            <div class="table-card">
              <div class="table-head-earn">Earnings</div>
              <table class="lines">
                <thead>
                  <tr>
                    <td>Description</td>
                    <td class="num" style="width:80px;">Amount (PHP)</td>
                  </tr>
                </thead>
                <tbody>
                  @foreach($displayDailyEarnLines as $line)
                    <tr>
                      <td>{{ strtolower(trim((string) ($line['label'] ?? ''))) === 'holiday premium' ? 'Holiday premium' : ($line['label'] ?? 'Daily computation earning') }}</td>
                      <td class="num">{{ $formatMoney($line['amount'] ?? 0) }}</td>
                    </tr>
                  @endforeach
                  @foreach($earnLines as $line)
                    <tr>
                      <td>{{ $line['label'] ?? 'Earning' }}</td>
                      <td class="num">{{ $formatMoney($line['amount'] ?? 0) }}</td>
                    </tr>
                  @endforeach
                  @if(count($displayDailyEarnLines) === 0 && count($earnLines) === 0)
                    <tr>
                      <td>No earnings computed.</td>
                      <td class="num">—</td>
                    </tr>
                  @endif
                  <tr class="total-row">
                    <td>Gross Pay</td>
                    <td class="num">{{ $formatMoney($payslip->gross_pay) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </td>
          <td>
            <div class="table-card">
              <div class="table-head-ded">Deductions</div>
              <table class="lines">
                <thead>
                  <tr>
                    <td>Description</td>
                    <td class="num" style="width:80px;">Amount (PHP)</td>
                  </tr>
                </thead>
                <tbody>
                  @foreach($govDedLines as $line)
                    <tr>
                      <td>{{ $line['label'] ?? 'Deduction' }}</td>
                      <td class="num">{{ $formatMoney($line['amount'] ?? 0) }}</td>
                    </tr>
                  @endforeach
                  @foreach($customDedLines as $line)
                    <tr>
                      <td>
                        {{ $line['label'] ?? 'Custom deduction' }}
                        @if(!empty($line['priority_bucket']))
                          <div class="holiday-note" style="color:#475569;">Priority: {{ $line['priority_bucket'] }}</div>
                        @endif
                        @if(!empty($line['legal_warning']))
                          <div class="holiday-note" style="color:#b91c1c;">{{ $line['legal_warning'] }}</div>
                        @endif
                      </td>
                      <td class="num">{{ $formatMoney($line['amount'] ?? 0) }}</td>
                    </tr>
                  @endforeach
                  @if(!$hasWithholdingLine)
                    <tr>
                      <td>Withholding tax</td>
                      <td class="num">{{ $formatMoney($summary['withholding_tax_this_period_estimate'] ?? 0) }}</td>
                    </tr>
                  @endif
                  @if(count($govDedLines) === 0 && count($customDedLines) === 0)
                    <tr>
                      <td>No additional deductions computed.</td>
                      <td class="num">—</td>
                    </tr>
                  @endif
                  <tr class="total-row ded">
                    <td>Total Deductions</td>
                    <td class="num">{{ $formatMoney($payslip->total_deductions) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </td>
        </tr>
      </table>

      {{-- Net Pay Hero --}}
      <div class="net-hero">
        <div class="net-hero-label">Net Take-Home Pay</div>
        <div class="net-hero-value">{{ $formatMoney($payslip->net_pay) }}</div>
        <div class="net-hero-period">For the period {{ $periodLabel }}</div>
      </div>
      <div class="foot-note">
        This is a system-generated document. Generated {{ now()->format('M d, Y h:i A') }}.
      </div>
    </div>
  </div>
</body>
</html>
