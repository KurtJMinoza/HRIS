<?php

namespace App\Support;

/** Workflow stages for multi-level HR approvals (corrections, overtime, leave). */
final class HrApprovalStages
{
    public const PENDING_FIRST = 'pending_first';

    public const PENDING_SECOND = 'pending_second';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';
}
