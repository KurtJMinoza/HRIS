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
  $period = $date($pay_period_start ?? $run->pay_period_start ?? null).' - '.$date($pay_period_end ?? $run->pay_period_end ?? null);
  $scopeLabel = $company_scope ?? ($company->name ?? 'All Companies');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <style>
    @page { size: A4 landscape; margin: 10mm; }
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #111827; }
    h1 { font-size: 18px; margin: 0 0 4px; }
    .meta { margin-bottom: 12px; color: #4b5563; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #d1d5db; padding: 6px 8px; text-align: left; }
    th { background: #f3f4f6; font-size: 10px; text-transform: uppercase; }
    td.num, th.num { text-align: right; }
    tfoot td { font-weight: bold; background: #f9fafb; }
  </style>
</head>
<body>
  <h1>Bank Payroll Export</h1>
  <div class="meta">
    <div><strong>{{ $title_row ?? ($bank_label ?? 'Bank Export') }}</strong></div>
    <div>Company: {{ $scopeLabel }}</div>
    <div>Pay Period: {{ $period }}</div>
    <div>Bank: {{ $bank_label ?? ($bank ?? '—') }}</div>
    <div>Employees: {{ count($rows ?? []) }}</div>
  </div>

  <table>
    <thead>
      <tr>
        <th>Employee No.</th>
        <th>Name</th>
        <th>Account No.</th>
        <th>Bank Code</th>
        <th class="num">Salary (Net Pay)</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($rows ?? [] as $row)
        <tr>
          <td>{{ $row['employee_no'] ?: '—' }}</td>
          <td>{{ $row['name'] }}</td>
          <td>{{ $row['account_number'] }}</td>
          <td>{{ $row['bank_code'] }}</td>
          <td class="num">{{ $money($row['salary']) }}</td>
        </tr>
      @endforeach
    </tbody>
    <tfoot>
      <tr>
        <td colspan="4">Total Net Pay</td>
        <td class="num">{{ $money($total_salary ?? 0) }}</td>
      </tr>
    </tfoot>
  </table>
</body>
</html>
