<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\EmployeeActivityTimelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeActivityLogController extends Controller
{
    private const ALLOWED_PER_PAGE = [25, 50, 100];

    private const CATEGORIES = [
        'all',
        'auth',
        'navigation',
        'attendance',
        'leave',
        'overtime',
        'correction',
        'schedule',
        'loan',
        'account',
    ];

    public function __construct(
        private readonly EmployeeActivityTimelineService $timeline,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $this->validatedFilters($request);

        return response()->json($this->timeline->list($request->user(), $validated));
    }

    public function show(Request $request, string $ref): JsonResponse
    {
        $detail = $this->timeline->detail($request->user(), $ref);
        abort_if($detail === null, 404, 'Activity not found.');

        return response()->json(['log' => $detail]);
    }

    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $validated = $this->validatedFilters($request);
        $rows = $this->timeline->exportRows($request->user(), $validated);

        if (($validated['format'] ?? 'csv') === 'json') {
            return response()->json(['rows' => $rows]);
        }

        $from = $validated['from_date'] ?? 'start';
        $to = $validated['to_date'] ?? 'end';
        $filename = sprintf('employee-activity_%s_%s.csv', $from, $to);

        return Response::streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Employee', 'Code', 'Category', 'Module', 'Action', 'Path', 'Summary', 'Device', 'IP', 'Status']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['occurred_at_label'] ?? '',
                    $row['employee_name'] ?? '',
                    $row['employee_code'] ?? '',
                    $row['category_label'] ?? '',
                    $row['module'] ?? '',
                    $row['title'] ?? '',
                    $row['path'] ?? '',
                    $row['summary'] ?? '',
                    $row['device_type'] ?? '',
                    $row['ip_address'] ?? '',
                    $row['status'] ?? '',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'category' => ['nullable', 'string', Rule::in(self::CATEGORIES)],
            'search' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in(self::ALLOWED_PER_PAGE)],
            'format' => ['nullable', 'string', Rule::in(['csv', 'json'])],
        ]);
    }
}
