<?php

namespace App\Support;

/** Suppresses verbose payroll logs during Redis draft batch generation. */
final class BulkPayrollDraftContext
{
    public static bool $active = false;
}
