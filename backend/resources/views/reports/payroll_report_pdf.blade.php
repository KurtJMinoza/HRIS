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
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <style>
    @page { margin: 5mm 4mm 8mm; }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      color: #1f2937;
      font-family: DejaVu Sans, Arial, sans-serif;
      font-size: 6.4px;
      line-height: 1.08;
      background: #ffffff;
    }
    body.report-compact { font-size: 5.8px; }
    body.report-wide { font-size: 5.3px; }
    body.report-ultra { font-size: 4.8px; }
    .report-shell {
      width: 100%;
      margin: 0 auto;
    }
    body.report-compact .report-shell,
    body.report-wide .report-shell,
    body.report-ultra .report-shell { width: 100%; }
    .hero {
      display: table;
      width: 100%;
      padding-bottom: 5px;
      margin-bottom: 5px;
      border-bottom: 1.2px solid #111827;
      color: #111827;
    }
    .hero-left, .hero-right {
      display: table-cell;
      vertical-align: top;
      width: 50%;
    }
    .hero-right { text-align: right; }
    .logo {
      width: 28px;
      height: 28px;
      object-fit: contain;
      display: inline-block;
      vertical-align: top;
      margin-right: 8px;
      border: 0.5px solid #d1d5db;
      background: #fff;
      padding: 1px;
    }
    .logo-fallback {
      width: 28px;
      height: 28px;
      display: inline-block;
      vertical-align: top;
      margin-right: 8px;
      border: 0.5px solid #d1d5db;
      background: #ffffff;
    }
    .company {
      display: inline-block;
      max-width: 440px;
      vertical-align: top;
    }
    .company-name {
      margin: 0 0 2px;
      font-size: 10.5px;
      font-weight: 800;
      color: #111827;
    }
    .company-address {
      margin: 0;
      color: #4b5563;
      font-size: 5.8px;
      line-height: 1.08;
    }
    .report-title {
      margin: 0 0 2px;
      color: #111827;
      font-size: 10.8px;
      font-weight: 800;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    .report-subtitle {
      margin: 0;
      color: #4b5563;
      font-size: 5.5px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
    }
    .meta-panel {
      display: table;
      width: 100%;
      margin-bottom: 5px;
      border-top: 0.5px solid #d1d5db;
      border-bottom: 0.5px solid #d1d5db;
      background: #ffffff;
    }
    .meta-block {
      display: table-cell;
      width: 20%;
      padding: 3px 5px 3px 0;
      border-right: 0;
      vertical-align: top;
    }
    .meta-block:last-child { border-right: 0; }
    .meta-label {
      display: block;
      margin-bottom: 1px;
      color: #6b7280;
      font-size: 5.1px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.06em;
    }
    .meta-value {
      display: block;
      color: #111827;
      font-size: 5.8px;
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
      font-size: 5.1px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.06em;
    }
    .summary-value {
      display: block;
      color: #111827;
      font-size: 7.1px;
      font-weight: 800;
      font-variant-numeric: tabular-nums;
    }
    .summary-value.net { color: #111827; }
    .section-title {
      margin: 1px 0 3px;
      color: #111827;
      font-size: 5.9px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.06em;
    }
    table.payroll-table {
      width: 100%;
      margin: 0 auto;
      border-collapse: collapse;
      table-layout: fixed;
      border: 1px solid #cbd5e1;
    }
    thead { display: table-header-group; }
    tfoot { display: table-row-group; }
    tr { page-break-inside: avoid; }
    th, td {
      border: 0.5px solid #d1d5db;
      padding: 1.6px 1.8px;
      vertical-align: middle;
      overflow-wrap: anywhere;
      word-break: break-word;
    }
    body.report-compact th, body.report-compact td { padding: 1.2px 1.4px; }
    body.report-wide th, body.report-wide td { padding: 1px 1.1px; }
    body.report-ultra th, body.report-ultra td { padding: 0.7px 0.8px; }
    th {
      background: #f3f4f6;
      color: #111827;
      font-size: 5.6px;
      font-weight: 800;
      line-height: 1.02;
      text-align: center;
      text-transform: uppercase;
      letter-spacing: 0;
    }
    body.report-standard th { font-size: 5.6px; }
    body.report-compact th { font-size: 5.2px; }
    body.report-wide th { font-size: 4.8px; }
    body.report-ultra th { font-size: 4.2px; }
    .group th {
      background: #e5e7eb;
      color: #111827;
      border-color: #cbd5e1;
      font-size: 5.4px;
      letter-spacing: 0.02em;
    }
    body.report-compact .group th { font-size: 5.3px; }
    body.report-wide .group th { font-size: 4.9px; }
    body.report-ultra .group th { font-size: 4.3px; }
    td.employee {
      font-weight: 700;
      text-align: left;
      color: #111827;
    }
    td.num {
      text-align: right;
      white-space: normal;
      word-break: normal;
      font-variant-numeric: tabular-nums;
      letter-spacing: -0.04em;
    }
    tbody tr:nth-child(even) td { background: #fafafa; }
    tbody tr:nth-child(odd) td { background: #ffffff; }
    .gross { font-weight: 700; }
    .deductions { color: #111827; font-weight: 700; }
    .net { color: #111827; font-weight: 800; }
    tfoot td {
      background: #f3f4f6;
      border-top: 1px solid #111827;
      font-weight: 800;
      color: #111827;
    }
    .note {
      margin-top: 5px;
      color: #6b7280;
      font-size: 5.1px;
      line-height: 1.15;
    }
    .footer {
      position: fixed;
      bottom: -5mm;
      left: 0;
      right: 0;
      display: table;
      width: 100%;
      padding-top: 3px;
      border-top: 0.5px solid #e5e7eb;
      color: #6b7280;
      font-size: 5.1px;
    }
    .footer-left, .footer-right {
      display: table-cell;
      width: 50%;
    }
    .footer-right { text-align: right; }
    .page-number:after { content: "Page " counter(page) " of " counter(pages); }
  </style>
</head>
<body class="{{ $layoutClass }}">
  <div class="report-shell">
    <div class="hero">
      <div class="hero-left">
        @if($logoLocalPath)
          <img class="logo" src="{{ $logoLocalPath }}" alt="">
        @else
          <span class="logo-fallback"></span>
        @endif
        <div class="company">
          <h1 class="company-name">{{ $company->name }}</h1>
          <p class="company-address">{{ $company->address ?: 'Company address not set' }}</p>
        </div>
      </div>
      <div class="hero-right">
        <h2 class="report-title">Payroll Report</h2>
        <p class="report-subtitle">Finalized Payroll Register</p>
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
          <col width="{{ $column['key'] === 'employee_name' ? $layout['employee_width'] : $layout['numeric_width'] }}%">
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
              @if($column['key'] === 'employee_name')
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
            @if($column['key'] === 'employee_name')
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
    <div class="footer-left">Payroll Report • {{ $company->name }} • Run #{{ $run->id }}</div>
    <div class="footer-right"><span class="page-number"></span></div>
  </div>
</body>
</html>
