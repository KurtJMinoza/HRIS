<?php

namespace App\Jobs;

use App\Http\Controllers\Admin\AttendanceMonitoringController;
use App\Services\AdminAttendanceCacheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AttendanceExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public readonly string $token,
        public readonly int $actorUserId,
        public readonly array $filters,
        public readonly string $format = 'csv',
    ) {
        $this->onQueue('attendance-export');
    }

    public function handle(AttendanceMonitoringController $controller): void
    {
        $statusKey = AdminAttendanceCacheService::exportStatusKey($this->token);
        AdminAttendanceCacheService::put($statusKey, [
            'status' => 'processing',
            'token' => $this->token,
            'updated_at' => now()->toIso8601String(),
        ], AdminAttendanceCacheService::EXPORT_STATUS_TTL);

        try {
            $request = Request::create('/api/admin/attendance/export', 'GET', $this->filters);
            $request->setUserResolver(fn () => \App\Models\User::query()->find($this->actorUserId));

            $response = $controller->export($request);
            $disk = Storage::disk('local');
            $dir = 'exports/attendance';
            $disk->makeDirectory($dir);

            if ($this->format === 'json') {
                $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
                $path = $dir.'/'.$this->token.'.json';
                $disk->put($path, json_encode($payload, JSON_THROW_ON_ERROR));
                $filename = 'attendance-export.json';
            } else {
                ob_start();
                $response->sendContent();
                $content = ob_get_clean();
                if (! is_string($content)) {
                    throw new \RuntimeException('Failed to capture CSV export stream.');
                }
                $path = $dir.'/'.$this->token.'.csv';
                $disk->put($path, $content);
                $filename = 'attendance-export.csv';
            }

            AdminAttendanceCacheService::put($statusKey, [
                'status' => 'ready',
                'token' => $this->token,
                'path' => $path,
                'filename' => $filename,
                'format' => $this->format,
                'updated_at' => now()->toIso8601String(),
            ], AdminAttendanceCacheService::EXPORT_STATUS_TTL);
        } catch (\Throwable $e) {
            Log::error('attendance_export.job_failed', [
                'token' => $this->token,
                'message' => $e->getMessage(),
            ]);
            AdminAttendanceCacheService::put($statusKey, [
                'status' => 'failed',
                'token' => $this->token,
                'message' => 'Export failed. Please try again.',
                'updated_at' => now()->toIso8601String(),
            ], AdminAttendanceCacheService::EXPORT_STATUS_TTL);
        }
    }
}
