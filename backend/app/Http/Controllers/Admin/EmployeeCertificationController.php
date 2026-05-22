<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AssertsEmployeeOrgScope;
use App\Http\Controllers\Controller;
use App\Models\EmployeeCertification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeCertificationController extends Controller
{
    use AssertsEmployeeOrgScope;

    private function serialize(EmployeeCertification $c): array
    {
        return [
            'id' => $c->id,
            'user_id' => $c->user_id,
            'certification_name' => $c->certification_name,
            'issuing_organization' => $c->issuing_organization,
            'issue_date' => optional($c->issue_date)->format('Y-m-d'),
            'expiration_date' => optional($c->expiration_date)->format('Y-m-d'),
            'credential_id' => $c->credential_id,
            'credential_url' => $c->credential_url,
            'certificate' => $c->certificate_path ? [
                'path' => $c->certificate_path,
                'url' => url('/api/media/public/'.$c->certificate_path),
                'mime' => $c->certificate_mime,
                'size' => (int) ($c->certificate_size ?? 0),
            ] : null,
            'verification_status' => $c->verification_status,
            'verified_by' => $c->verified_by,
            'verified_at' => $c->verified_at?->toISOString(),
            'rejection_reason' => $c->rejection_reason,
            'created_at' => $c->created_at?->toISOString(),
            'updated_at' => $c->updated_at?->toISOString(),
        ];
    }

    public function index(Request $request, int $userId): JsonResponse
    {
        $employee = User::where('id', $userId)->visibleEmployees()->firstOrFail();
        $this->assertEmployeeOrgScope($request, $employee);

        $items = EmployeeCertification::where('user_id', $employee->id)
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmployeeCertification $c) => $this->serialize($c))
            ->values();

        return response()->json(['certifications' => $items]);
    }

    public function store(Request $request, int $userId): JsonResponse
    {
        $employee = User::where('id', $userId)->visibleEmployees()->firstOrFail();
        $this->assertEmployeeOrgScope($request, $employee);

        $validated = $request->validate([
            'certification_name' => ['required', 'string', 'max:180'],
            'issuing_organization' => ['required', 'string', 'max:180'],
            'issue_date' => ['required', 'date', 'before_or_equal:today'],
            'expiration_date' => ['nullable', 'date'],
            'credential_id' => ['nullable', 'string', 'max:120'],
            'credential_url' => ['nullable', 'url', 'max:500'],
            'certificate_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $issue = $validated['issue_date'];
        $exp = $validated['expiration_date'] ?? null;
        if ($exp && $issue && strtotime($exp) <= strtotime($issue)) {
            throw ValidationException::withMessages(['expiration_date' => ['Expiration date must be after issue date.']]);
        }

        $path = null;
        $mime = null;
        $size = 0;
        if ($request->hasFile('certificate_file')) {
            $file = $request->file('certificate_file');
            $path = $file->store('certifications', 'public');
            $mime = $file->getClientMimeType() ?: $file->getMimeType();
            $size = (int) $file->getSize();
        }

        $cert = EmployeeCertification::create([
            'user_id' => $employee->id,
            'certification_name' => trim((string) $validated['certification_name']),
            'issuing_organization' => trim((string) $validated['issuing_organization']),
            'issue_date' => $validated['issue_date'],
            'expiration_date' => $validated['expiration_date'] ?? null,
            'credential_id' => isset($validated['credential_id']) ? trim((string) $validated['credential_id']) : null,
            'credential_url' => $validated['credential_url'] ?? null,
            'certificate_path' => $path,
            'certificate_mime' => $mime,
            'certificate_size' => $size,
            'verification_status' => 'pending',
            'verified_by' => null,
            'verified_at' => null,
            'rejection_reason' => null,
        ]);

        return response()->json([
            'message' => 'Certification added.',
            'certification' => $this->serialize($cert),
        ], 201);
    }

    public function update(Request $request, int $userId, int $id): JsonResponse
    {
        $employee = User::where('id', $userId)->visibleEmployees()->firstOrFail();
        $this->assertEmployeeOrgScope($request, $employee);
        $cert = EmployeeCertification::where('id', $id)->where('user_id', $employee->id)->firstOrFail();

        $validated = $request->validate([
            'certification_name' => ['required', 'string', 'max:180'],
            'issuing_organization' => ['required', 'string', 'max:180'],
            'issue_date' => ['required', 'date', 'before_or_equal:today'],
            'expiration_date' => ['nullable', 'date'],
            'credential_id' => ['nullable', 'string', 'max:120'],
            'credential_url' => ['nullable', 'url', 'max:500'],
            'certificate_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'verification_status' => ['nullable', Rule::in(['pending', 'verified', 'rejected'])],
        ]);

        $issue = $validated['issue_date'];
        $exp = $validated['expiration_date'] ?? null;
        if ($exp && $issue && strtotime($exp) <= strtotime($issue)) {
            throw ValidationException::withMessages(['expiration_date' => ['Expiration date must be after issue date.']]);
        }

        if ($request->hasFile('certificate_file')) {
            if ($cert->certificate_path) {
                Storage::disk('public')->delete($cert->certificate_path);
            }
            $file = $request->file('certificate_file');
            $cert->certificate_path = $file->store('certifications', 'public');
            $cert->certificate_mime = $file->getClientMimeType() ?: $file->getMimeType();
            $cert->certificate_size = (int) $file->getSize();
        }

        $cert->certification_name = trim((string) $validated['certification_name']);
        $cert->issuing_organization = trim((string) $validated['issuing_organization']);
        $cert->issue_date = $validated['issue_date'];
        $cert->expiration_date = $validated['expiration_date'] ?? null;
        $cert->credential_id = isset($validated['credential_id']) ? trim((string) $validated['credential_id']) : null;
        $cert->credential_url = $validated['credential_url'] ?? null;

        // If admin edits certification data, reset to pending unless explicitly set.
        if (isset($validated['verification_status'])) {
            $cert->verification_status = $validated['verification_status'];
        } else {
            $cert->verification_status = 'pending';
        }
        if ($cert->verification_status === 'pending') {
            $cert->verified_by = null;
            $cert->verified_at = null;
            $cert->rejection_reason = null;
        }

        $cert->save();

        return response()->json([
            'message' => 'Certification updated.',
            'certification' => $this->serialize($cert),
        ]);
    }

    public function destroy(Request $request, int $userId, int $id): JsonResponse
    {
        $employee = User::where('id', $userId)->visibleEmployees()->firstOrFail();
        $this->assertEmployeeOrgScope($request, $employee);
        $cert = EmployeeCertification::where('id', $id)->where('user_id', $employee->id)->firstOrFail();

        if ($cert->certificate_path) {
            Storage::disk('public')->delete($cert->certificate_path);
        }
        $cert->delete();

        return response()->json(['message' => 'Certification deleted.']);
    }

    public function verify(Request $request, int $userId, int $id): JsonResponse
    {
        $employee = User::where('id', $userId)->visibleEmployees()->firstOrFail();
        $this->assertEmployeeOrgScope($request, $employee);
        $cert = EmployeeCertification::where('id', $id)->where('user_id', $employee->id)->firstOrFail();

        $validated = $request->validate([
            'status' => ['required', Rule::in(['verified', 'rejected'])],
            'rejection_reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validated['status'] === 'rejected' && trim((string) ($validated['rejection_reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['rejection_reason' => ['Rejection reason is required when rejecting.']]);
        }

        $cert->verification_status = $validated['status'];
        $cert->verified_by = (int) $request->user()->id;
        $cert->verified_at = now();
        $cert->rejection_reason = $validated['status'] === 'rejected' ? trim((string) $validated['rejection_reason']) : null;
        $cert->save();

        return response()->json([
            'message' => 'Verification updated.',
            'certification' => $this->serialize($cert),
        ]);
    }
}
