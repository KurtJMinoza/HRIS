<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveController extends Controller
{
    /**
     * List leave requests. Optional filter: status = pending | approved | rejected.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $query = LeaveRequest::with('user:id,name,profile_image', 'reviewedByUser:id,name');

        if (in_array($status, [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_REJECTED], true)) {
            $query->where('status', $status);
        }

        $leaves = $query->orderByDesc('created_at')->get()->map(fn (LeaveRequest $l) => [
            'id' => $l->id,
            'employee_id' => $l->user_id,
            'employee_name' => $l->user?->name,
            'employee_profile_image' => $l->user?->profile_image ? asset('storage/' . $l->user->profile_image) : null,
            'type' => $l->type,
            'start_date' => $l->start_date->toDateString(),
            'end_date' => $l->end_date->toDateString(),
            'undertime_time' => $l->undertime_time ? substr((string) $l->undertime_time, 0, 5) : null,
            'half_type' => $l->half_type,
            'status' => $l->status,
            'notes' => $l->notes,
            'reviewed_at' => $l->reviewed_at?->toIso8601String(),
            'reviewed_by_name' => $l->reviewedByUser?->name,
            'created_at' => $l->created_at->toIso8601String(),
        ]);

        return response()->json(['leave_requests' => $leaves]);
    }

    /**
     * Create a leave request (admin creating on behalf of employee, or for testing).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'type' => ['required', 'string', 'max:50', 'in:vacation,sick,emergency,other,undertime,half_day'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'half_type' => ['nullable', 'string', 'in:am,pm'],
        ]);

        if ($validated['type'] === 'half_day') {
            // Half-day leave from admin side is always a single calendar date.
            $validated['end_date'] = $validated['start_date'];
            if (empty($validated['half_type'])) {
                return response()->json([
                    'message' => 'Half day type (AM or PM) is required.',
                ], 422);
            }
        }

        $leave = LeaveRequest::create([
            'user_id' => $validated['user_id'],
            'type' => $validated['type'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'half_type' => $validated['type'] === 'half_day' ? ($validated['half_type'] ?? null) : null,
            'status' => LeaveRequest::STATUS_PENDING,
        ]);

        return response()->json([
            'message' => 'Leave request created.',
            'leave_request' => $this->leaveResponse($leave),
        ], 201);
    }

    /**
     * Approve a leave request.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $leave = LeaveRequest::findOrFail($id);
        if ($leave->status !== LeaveRequest::STATUS_PENDING) {
            return response()->json(['message' => 'Leave request is not pending.'], 422);
        }
        $leave->update([
            'status' => LeaveRequest::STATUS_APPROVED,
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Leave request approved.',
            'leave_request' => $this->leaveResponse($leave->fresh(['user:id,name', 'reviewedByUser:id,name'])),
        ]);
    }

    /**
     * Reject a leave request.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $leave = LeaveRequest::findOrFail($id);
        if ($leave->status !== LeaveRequest::STATUS_PENDING) {
            return response()->json(['message' => 'Leave request is not pending.'], 422);
        }
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $leave->update([
            'status' => LeaveRequest::STATUS_REJECTED,
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()?->id,
            'notes' => $validated['reason'],
        ]);

        return response()->json([
            'message' => 'Leave request rejected.',
            'leave_request' => $this->leaveResponse($leave->fresh(['user:id,name', 'reviewedByUser:id,name'])),
        ]);
    }

    /**
     * Add or update notes on a leave request.
     */
    public function updateNotes(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $leave = LeaveRequest::findOrFail($id);
        $leave->update(['notes' => $validated['notes'] ?? null]);

        return response()->json([
            'message' => 'Notes updated.',
            'leave_request' => $this->leaveResponse($leave->fresh(['user:id,name', 'reviewedByUser:id,name'])),
        ]);
    }

    private function leaveResponse(LeaveRequest $l): array
    {
        return [
            'id' => $l->id,
            'employee_id' => $l->user_id,
            'employee_name' => $l->user?->name,
            'type' => $l->type,
            'start_date' => $l->start_date->toDateString(),
            'end_date' => $l->end_date->toDateString(),
            'undertime_time' => $l->undertime_time ? substr((string) $l->undertime_time, 0, 5) : null,
            'half_type' => $l->half_type,
            'status' => $l->status,
            'notes' => $l->notes,
            'reviewed_at' => $l->reviewed_at?->toIso8601String(),
            'reviewed_by_name' => $l->reviewedByUser?->name,
            'created_at' => $l->created_at->toIso8601String(),
        ];
    }
}
