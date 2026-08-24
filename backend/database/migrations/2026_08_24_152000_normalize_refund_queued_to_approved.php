<?php

use App\Models\RefundRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('refund_requests')
            ->where('status', RefundRequest::STATUS_QUEUED_FOR_PAYROLL)
            ->update(['status' => RefundRequest::STATUS_APPROVED]);
    }

    public function down(): void
    {
        // Legacy queued rows cannot be restored reliably.
    }
};
