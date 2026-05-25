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
  $layoutClass = $paperSize === 'a3'
      ? 'report-wide'
      : ($paperSize === 'legal' ? 'report-compact' : 'report-standard');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <style>
    @page { margin: 7mm 6mm 9mm; }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      color: #111827;
      font-family: DejaVu Sans, Arial, sans-serif;
      font-size: 6.8px;
      line-height: 1.16;
      background: #ffffff;
    }
    body.report-compact { font-size: 6.2px; }
    body.report-wide { font-size: 5.8px; }
    .header {
      display: table;
      width: 100%;
      padding-bottom: 7px;
      margin-bottom: 7px;
      border-bottom: 1px solid #d1d5db;
    }
    .header-left, .header-right {
      display: table-cell;
      vertical-align: top;
      width: 50%;
    }
    .header-right { text-align: right; }
    .logo {
      width: 36px;
      height: 36px;
      object-fit: contain;
      display: inline-block;
      vertical-align: top;
      margin-right: 8px;
      border: 1px solid #e5e7eb;
      border-radius: 4px;
      background: #fff;
    }
    .logo-fallback {
      width: 36px;
      height: 36px;
      display: inline-block;
      vertical-align: top;
      margin-right: 8px;
      border: 1px solid #d1d5db;
      border-radius: 4px;
      background: #f9fafb;
    }
    .company {
      display: inline-block;
      max-width: 440px;
      vertical-align: top;
    }
    .company-name {
      margin: 0 0 2px;
      font-size: 11.5px;
      font-weight: 800;
      color: #111827;
    }
    .company-address {
      margin: 0;
      color: #4b5563;
      font-size: 6.4px;
      line-height: 1.18;
    }
    .report-title {
      margin: 0 0 5px;
      color: #111827;
      font-size: 13px;
      font-weight: 800;
      letter-spacing: 0.01em;
    }
    .meta { display: inline-block; min-width: 220px; text-align: left; }
    .meta-row { display: block; clear: both; margin-bottom: 1.5px; }
    .meta-label {
      display: inline-block;
      width: 78px;
      color: #6b7280;
      font-size: 5.8px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }
    .meta-value { color: #111827; font-size: 6.3px; font-weight: 600; }
    table.payroll-table {
      width: 82%;
      margin-left: auto;
      margin-right: auto;
      border-collapse: collapse;
      table-layout: fixed;
      border: 1px solid #cbd5e1;
    }
    thead { display: table-header-group; }
    tfoot { display: table-row-group; }
    tr { page-break-inside: avoid; }
    th, td {
      border: 0.5px solid #d1d5db;
      padding: 2.8px 3px;
      vertical-align: middle;
      overflow-wrap: anywhere;
      word-break: break-word;
    }
    body.report-compact th, body.report-compact td { padding: 2.4px 2.6px; }
    body.report-wide th, body.report-wide td { padding: 2px 2.2px; }
    th {
      background: #f8fafc;
      color: #374151;
      font-size: 5.8px;
      font-weight: 800;
      line-height: 1.08;
      text-align: center;
      text-transform: uppercase;
      letter-spacing: 0.015em;
    }
    body.report-compact th { font-size: 5.8px; }
    body.report-wide th { font-size: 5.4px; }
    .group th {
      background: #eef2f7;
      color: #111827;
      font-size: 6px;
      letter-spacing: 0.03em;
    }
    body.report-compact .group th { font-size: 6.1px; }
    body.report-wide .group th { font-size: 5.8px; }
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
      letter-spacing: -0.02em;
    }
    tbody tr:nth-child(even) td { background: #fbfdff; }
    tbody tr:nth-child(odd) td { background: #ffffff; }
    .gross { font-weight: 700; }
    .deductions { color: #991b1b; font-weight: 700; }
    .net { color: #166534; font-weight: 800; }
    tfoot td {
      background: #f1f5f9;
      border-top: 1.5px solid #64748b;
      font-weight: 800;
      color: #111827;
    }
    .footer {
      position: fixed;
      bottom: -6mm;
      left: 0;
      right: 0;
      display: table;
      width: 100%;
      padding-top: 3px;
      border-top: 0.5px solid #e5e7eb;
      color: #6b7280;
      font-size: 6px;
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
  <div class="header">
    <div class="header-left">
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
    <div class="header-right">
      <h2 class="report-title">Payroll Report</h2>
      <div class="meta">
        <div class="meta-row"><span class="meta-label">Period</span><span class="meta-value">{{ $period }}</span></div>
        <div class="meta-row"><span class="meta-label">Pay Date</span><span class="meta-value">{{ $payDate }}</span></div>
        <div class="meta-row"><span class="meta-label">Generated</span><span class="meta-value">{{ $date($generatedAt) }}</span></div>
        <div class="meta-row"><span class="meta-label">Generated By</span><span class="meta-value">{{ $generatedBy }}</span></div>
        <div class="meta-row"><span class="meta-label">Run</span><span class="meta-value">#{{ $run->id }}</span></div>
      </div>
    </div>
  </div>

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

  <div class="footer">
    <div class="footer-left">Payroll Report • {{ $company->name }} • Run #{{ $run->id }}</div>
    <div class="footer-right"><span class="page-number"></span></div>
  </div>
</body>
</html>
