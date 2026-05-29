@php
  $money = static fn ($value): string => number_format((float) ($value ?? 0), 2);
  $date = static function ($value): string {
      if (empty($value)) return '—';
      try {
          return \Illuminate\Support\Carbon::parse($value)->format('M d, Y');
      } catch (\Throwable) {
          return (string) $value;
      }
  };
  $period = $date($run->pay_period_start).' - '.$date($run->pay_period_end);
  $payDate = $date($reportPayDate ?? ($run->reference_date ?? null));
  $columns = $columns ?? [];
  $layout = $layout ?? [
      'body_font' => '7.2px',
      'header_font' => '6.2px',
      'cell_padding' => '2.8px 3px',
      'employee_width' => 16,
      'numeric_width' => 6,
      'orientation' => 'portrait',
  ];
  $groupSpans = [];
  foreach ($columns as $column) {
      $group = (string) ($column['group'] ?? '');
      $groupSpans[$group] = ($groupSpans[$group] ?? 0) + 1;
  }
  $paperSize = (string) ($layout['paper_size'] ?? 'a4');
  $columnCount = count($columns);
  $layoutClass = match (true) {
      $columnCount >= 22 => 'report-ultra',
      $columnCount >= 15 => 'report-wide',
      $columnCount >= 11 => 'report-compact',
      default => 'report-standard',
  };
  $employeeCount = is_countable($rows ?? []) ? count($rows) : 0;
  $reportCompanyName = (string) ($reportCompanyName ?? ($company->name ?? 'Company'));
  $reportCompanyAddress = $reportCompanyAddress ?? ($company->address ?? null);
  $isExecomPayroll = (bool) ($isExecomPayroll ?? false);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <style>
    @page { size: A4 portrait; margin: 5mm 5mm 8mm; }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      color: #111827;
      font-family: DejaVu Sans, Arial, sans-serif;
      font-size: var(--report-body-font, 12.2px);
      line-height: 1.12;
      background: #ffffff;
    }
    body.report-compact { font-size: var(--report-body-font, 11.4px); }
    body.report-wide { font-size: var(--report-body-font, 10.0px); }
    body.report-ultra { font-size: var(--report-body-font, 9.4px); }
    .report-shell {
      width: 100%;
      max-width: 100%;
      margin: 0 auto;
      padding: 0;
    }
    body.report-compact .report-shell,
    body.report-wide .report-shell,
    body.report-ultra .report-shell {
      width: 100%;
      max-width: 100%;
    }
    .hero {
      display: table;
      width: 100%;
      padding: 0 0 5px;
      margin-bottom: 5px;
      border-bottom: 1.4px solid #111827;
      color: #111827;
    }
    .hero-left, .hero-right {
      display: table-cell;
      vertical-align: top;
      width: 50%;
    }
    .hero-right { text-align: right; }
    .logo {
      width: 30px;
      height: 30px;
      display: inline-block;
      vertical-align: top;
      margin-right: 8px;
      border: 0.5px solid #d1d5db;
      background: #fff;
      padding: 1px;
    }
    .logo-fallback {
      width: 30px;
      height: 30px;
      display: inline-block;
      vertical-align: top;
      margin-right: 8px;
      border: 0.5px solid #d1d5db;
      background: #ffffff;
    }
    .company {
      display: inline-block;
      max-width: 62%;
      vertical-align: top;
    }
    .company-name {
      margin: 0 0 2px;
      font-size: 12.5px;
      font-weight: 800;
      color: #111827;
    }
    .company-address {
      margin: 0;
      color: #4b5563;
      font-size: 6.8px;
      line-height: 1.18;
    }
    .report-title {
      margin: 0 0 2px;
      color: #111827;
      font-size: 13px;
      font-weight: 800;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    .report-subtitle {
      margin: 0;
      color: #4b5563;
      font-size: 6.8px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
    }
    .meta-panel {
      display: table;
      width: 100%;
      margin-bottom: 5px;
      border: 0.5px solid #cbd5e1;
      background: #f8fafc;
    }
    .meta-block {
      display: table-cell;
      width: 20%;
      padding: 3px 5px;
      border-right: 0.5px solid #e5e7eb;
      vertical-align: top;
    }
    .meta-block:last-child { border-right: 0; }
    .meta-label {
      display: block;
      margin-bottom: 1px;
      color: #6b7280;
      font-size: 6.8px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.06em;
    }
    .meta-value {
      display: block;
      color: #111827;
      font-size: 7.6px;
      font-weight: 700;
      line-height: 1.18;
    }
    .summary {
      display: table;
      width: 100%;
      margin-bottom: 5px;
      border: 0.5px solid #cbd5e1;
      border-collapse: collapse;
    }
    .summary-card {
      display: table-cell;
      width: 25%;
      padding: 0;
      border-right: 0.5px solid #cbd5e1;
    }
    .summary-card:last-child { border-right: 0; }
    .summary-inner {
      padding: 3px 5px;
      background: #f8fafc;
    }
    .summary-label {
      display: block;
      margin-bottom: 2px;
      color: #6b7280;
      font-size: 6.8px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.06em;
    }
    .summary-value {
      display: block;
      color: #111827;
      font-size: 9px;
      font-weight: 800;
      font-variant-numeric: tabular-nums;
    }
    .summary-value.net { color: #111827; }
    .section-title {
      margin: 2px 0 4px;
      color: #111827;
      font-size: 8px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.06em;
    }
    table.payroll-table {
      width: 100%;
      max-width: 100%;
      margin: 0 auto;
      border-collapse: collapse;
      table-layout: fixed;
      border: 0.7px solid #94a3b8;
      font-size: inherit;
    }
    table.payroll-table col.col-number { width: var(--number-col-width, 4%); }
    table.payroll-table col.col-employee { width: var(--employee-col-width, 30%); }
    table.payroll-table col.col-numeric { width: var(--numeric-col-width, 7%); }
    thead { display: table-header-group; }
    tfoot { display: table-row-group; }
    tr { page-break-inside: avoid; }
    th, td {
      border: 0.45px solid #d1d5db;
      padding: var(--report-cell-padding, 1.5px 1.7px);
      vertical-align: middle;
      overflow-wrap: anywhere;
      word-break: break-word;
      overflow: hidden;
    }
    body.report-compact th, body.report-compact td,
    body.report-wide th, body.report-wide td,
    body.report-ultra th, body.report-ultra td { padding: var(--report-cell-padding, 1px 1.1px); }
    th {
      background: #f8fafc;
      color: #111827;
      font-size: var(--report-header-font, 10.5px);
      font-weight: 800;
      line-height: 1.04;
      text-align: center;
      text-transform: uppercase;
      letter-spacing: 0;
    }
    body.report-standard th,
    body.report-compact th,
    body.report-wide th,
    body.report-ultra th { font-size: var(--report-header-font, 10.5px); }
    .group th {
      background: #111827;
      color: #ffffff;
      border-color: #111827;
      font-size: var(--report-header-font, 10.2px);
      letter-spacing: 0.02em;
    }
    body.report-compact .group th,
    body.report-wide .group th,
    body.report-ultra .group th { font-size: var(--report-header-font, 10.2px); }
    td.employee {
      font-weight: 700;
      text-align: left;
      color: #111827;
      line-height: 1.16;
      overflow-wrap: anywhere;
      font-size: 1.02em;
    }
    td.num {
      text-align: right;
      white-space: normal;
      overflow-wrap: anywhere;
      word-break: break-word;
      font-variant-numeric: tabular-nums;
      letter-spacing: -0.045em;
      font-size: 1em;
      line-height: 1.14;
    }
    td.num.row-number {
      text-align: center;
      font-weight: 700;
      letter-spacing: 0;
      white-space: nowrap;
    }
    tbody tr:nth-child(even) td { background: #fafafa; }
    tbody tr:nth-child(odd) td { background: #ffffff; }
    .gross { font-weight: 700; }
    .deductions { color: #111827; font-weight: 700; }
    .net { color: #111827; font-weight: 800; }
    tfoot td {
      background: #eef2f7;
      border-top: 1px solid #111827;
      font-weight: 800;
      color: #111827;
    }
    .note {
      margin-top: 5px;
      color: #6b7280;
      font-size: 6.8px;
      line-height: 1.15;
    }
    .footer {
      position: fixed;
      bottom: -7mm;
      left: 0;
      right: auto;
      display: table;
      width: 100%;
      margin: 0 auto;
      padding-top: 3px;
      border-top: 0.5px solid #e5e7eb;
      color: #6b7280;
      font-size: 6.8px;
    }
    .footer-left, .footer-right {
      display: table-cell;
      width: 50%;
    }
    .footer-right { text-align: right; }
    .page-number:after { content: "Page " counter(page) " of " counter(pages); }
  </style>
</head>
<body
  class="{{ $layoutClass }}"
  style="--report-body-font: {{ $layout['body_font'] ?? '6.2px' }}; --report-header-font: {{ $layout['header_font'] ?? '5.5px' }}; --report-cell-padding: {{ $layout['cell_padding'] ?? '1.4px 1.6px' }}; --number-col-width: {{ $layout['row_number_width'] ?? 4 }}%; --employee-col-width: {{ $layout['employee_width'] ?? 30 }}%; --numeric-col-width: {{ $layout['numeric_width'] ?? 7 }}%;"
>
  <div class="report-shell">
    <div class="hero">
      <div class="hero-left">
        @if($logoLocalPath)
          <img class="logo" src="{{ $logoLocalPath }}" alt="">
        @elseif(! $isExecomPayroll)
          <span class="logo-fallback"></span>
        @endif
        <div class="company">
          <h1 class="company-name">{{ $reportCompanyName }}</h1>
          @if(! $isExecomPayroll)
            <p class="company-address">{{ $reportCompanyAddress ?: 'Company address not set' }}</p>
          @endif
        </div>
      </div>
      <div class="hero-right">
        <h2 class="report-title">{{ $isExecomPayroll ? 'EXECOM Payroll Report' : 'Payroll Report' }}</h2>
        <p class="report-subtitle">{{ $isExecomPayroll ? 'Finalized EXECOM Payroll Register' : 'Finalized Payroll Register' }}</p>
      </div>
    </div>

    <div class="meta-panel">
      <div class="meta-block">
        <span class="meta-label">Payroll Period</span>
        <span class="meta-value">{{ $period }}</span>
      </div>
      <div class="meta-block">
        <span class="meta-label">Pay Date</span>
        <span class="meta-value">{{ $payDate }}</span>
      </div>
      <div class="meta-block">
        <span class="meta-label">Employees</span>
        <span class="meta-value">{{ number_format($employeeCount) }}</span>
      </div>
      <div class="meta-block">
        <span class="meta-label">Generated By</span>
        <span class="meta-value">{{ $generatedBy }}</span>
      </div>
      <div class="meta-block">
        <span class="meta-label">Run Reference</span>
        <span class="meta-value">#{{ $run->id }} • {{ $date($generatedAt) }}</span>
      </div>
    </div>

    <div class="summary">
      <div class="summary-card">
        <div class="summary-inner">
          <span class="summary-label">Gross Earnings</span>
          <span class="summary-value">{{ $money($totals['gross_earnings'] ?? 0) }}</span>
        </div>
      </div>
      <div class="summary-card">
        <div class="summary-inner">
          <span class="summary-label">Total Deductions</span>
          <span class="summary-value">{{ $money($totals['total_deductions'] ?? 0) }}</span>
        </div>
      </div>
      <div class="summary-card">
        <div class="summary-inner">
          <span class="summary-label">Net Payroll</span>
          <span class="summary-value net">{{ $money($totals['net_pay'] ?? 0) }}</span>
        </div>
      </div>
      <div class="summary-card">
        <div class="summary-inner">
          <span class="summary-label">Employee Count</span>
          <span class="summary-value">{{ number_format($employeeCount) }} Employees</span>
        </div>
      </div>
    </div>

    <div class="section-title">Employee Payroll Breakdown</div>

    <table class="payroll-table">
      <colgroup>
        @foreach($columns as $column)
          <col
            class="{{ $column['key'] === 'row_number' ? 'col-number' : ($column['key'] === 'employee_name' ? 'col-employee' : 'col-numeric') }}"
            width="{{ $column['key'] === 'row_number' ? ($layout['row_number_width'] ?? 4) : ($column['key'] === 'employee_name' ? $layout['employee_width'] : $layout['numeric_width']) }}%"
          >
        @endforeach
      </colgroup>
      <thead>
        <tr class="group">
          @foreach($groupSpans as $group => $span)
            <th colspan="{{ $span }}">{{ $group }}</th>
          @endforeach
        </tr>
        <tr>
          @foreach($columns as $column)
            <th>{{ $column['label'] }}</th>
          @endforeach
        </tr>
      </thead>
      <tbody>
        @foreach($rows as $row)
          <tr>
            @foreach($columns as $column)
              @if($column['key'] === 'row_number')
                <td class="{{ $column['class'] }}">{{ $row[$column['key']] }}</td>
              @elseif($column['key'] === 'employee_name')
                <td class="{{ $column['class'] }}">{{ $row[$column['key']] }}</td>
              @else
                <td class="{{ $column['class'] }}">{{ $money($row[$column['key']] ?? 0) }}</td>
              @endif
            @endforeach
          </tr>
        @endforeach
      </tbody>
      <tfoot>
        <tr>
          @foreach($columns as $column)
            @if($column['key'] === 'row_number')
              <td></td>
            @elseif($column['key'] === 'employee_name')
              <td>Total</td>
            @else
              <td class="{{ $column['class'] }}">{{ $money($totals[$column['key']] ?? 0) }}</td>
            @endif
          @endforeach
        </tr>
      </tfoot>
    </table>

    <div class="note">
      Source: finalized payroll records for the payroll run indicated above.
    </div>
  </div>

  <div class="footer">
    <div class="footer-left">{{ $isExecomPayroll ? 'EXECOM Payroll Report' : 'Payroll Report' }} • {{ $reportCompanyName }} • Run #{{ $run->id }}</div>
    <div class="footer-right"><span class="page-number"></span></div>
  </div>
</body>
</html>
