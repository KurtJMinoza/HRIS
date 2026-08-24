<?php

namespace App\Contracts;

/**
 * Bulk payroll runners flush in-memory engine caches between employees or finalize passes.
 */
interface ClearsPayrollRuntimeCaches
{
    public function flushRuntimeCaches(): void;
}
