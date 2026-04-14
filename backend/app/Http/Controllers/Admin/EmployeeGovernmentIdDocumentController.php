<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AssertsEmployeeOrgScope;
use App\Http\Controllers\Controller;
use App\Models\EmployeeGovernmentIdDocument;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeGovernmentIdDocumentController extends Controller
{
    use AssertsEmployeeOrgScope;

    private function serialize(EmployeeGovernmentIdDocument $d): array
    {
        return [
            'id' => $d->id,
            'user_id' => $d->user_id,
            'id_type' => $d->id_type,
            'id_number' => $d->id_number,
            'issuing_agency' => $d->issuing_agency,
            'expiry_date' => optional($d->expiry_date)->format('Y-m-d'),
            'document' => $d->document_path ? [
                'path' => $d->document_path,
                'url' => url('/api/media/public/'.$d->document_path),
                'mime' => $d->document_mime,
                'size' => (int) ($d->document_size ?? 0),
            ] : null,
            'status' => $d->status,
            'verified_by' => $d->verified_by,
            'verified_at' => $d->verified_at?->toISOString(),
            'rejection_reason' => $d->rejection_reason,
            'created_at' => $d->created_at?->toISOString(),
        ];
    }

    public function index(Request $request, int $userId): JsonResponse
    {
        $employee = User::where('id', $userId)->where('role', User::ROLE_EMPLOYEE)->firstOrFail();
        $this->assertEmployeeOrgScope($request, $employee);

        $items = EmployeeGovernmentIdDocument::where('user_id', $employee->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmployeeGovernmentIdDocument $d) => $this->serialize($d))
            ->values();

        return response()->json(['government_ids' => $items]);
    }

    public function store(Request $request, int $userId): JsonResponse
    {
        $employee = User::where('id', $userId)->where('role', User::ROLE_EMPLOYEE)->firstOrFail();
        $this->assertEmployeeOrgScope($request, $employee);

        $validated = $request->validate([
            'id_type' => ['required', 'string', 'max:60'],
            'id_number' => ['required', 'string', 'max:120'],
            'issuing_agency' => ['required', 'string', 'max:180'],
            'expiry_date' => ['nullable', 'date'],
            'document_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $idNumber = trim((string) $validated['id_number']);
        $exists = EmployeeGovernmentIdDocument::where('user_id', $employee->id)->whereRaw('LOWER(id_number) = ?', [mb_strtolower($idNumber)])->exists();
        if ($exists) {
            throw ValidationException::withMessages(['id_number' => ['Duplicate ID number for this employee.']]);
        }

        $file = $request->file('document_file');
        $path = $file->store('government-ids', 'public');
        $mime = $file->getClientMimeType() ?: $file->getMimeType();
        $size = (int) $file->getSize();

        $doc = EmployeeGovernmentIdDocument::create([
            'user_id' => $employee->id,
            'id_type' => trim((string) $validated['id_type']),
            'id_number' => $idNumber,
            'issuing_agency' => trim((string) $validated['issuing_agency']),
            'expiry_date' => $validated['expiry_date'] ?? null,
            'document_path' => $path,
            'document_mime' => $mime,
            'document_size' => $size,
            'status' => 'pending',
            'verified_by' => null,
            'verified_at' => null,
            'rejection_reason' => null,
        ]);

        return response()->json([
            'message' => 'Government ID uploaded.',
            'government_id' => $this->serialize($doc),
        ], 201);
    }

    public function update(Request $request, int $userId, int $id): JsonResponse
    {
        $employee = User::where('id', $userId)->where('role', User::ROLE_EMPLOYEE)->firstOrFail();
        $this->assertEmployeeOrgScope($request, $employee);
        $doc = EmployeeGovernmentIdDocument::where('id', $id)->where('user_id', $employee->id)->firstOrFail();

        $validated = $request->validate([
            'id_type' => ['required', 'string', 'max:60'],
            'id_number' => ['required', 'string', 'max:120'],
            'issuing_agency' => ['required', 'string', 'max:180'],
            'expiry_date' => ['nullable', 'date'],
            'document_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $idNumber = trim((string) $validated['id_number']);
        $exists = EmployeeGovernmentIdDocument::where('user_id', $employee->id)
            ->where('id', '!=', $doc->id)
            ->whereRaw('LOWER(id_number) = ?', [mb_strtolower($idNumber)])
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['id_number' => ['Duplicate ID number for this employee.']]);
        }

        if ($request->hasFile('document_file')) {
            if ($doc->document_path) {
                Storage::disk('public')->delete($doc->document_path);
            }
            $file = $request->file('document_file');
            $doc->document_path = $file->store('government-ids', 'public');
            $doc->document_mime = $file->getClientMimeType() ?: $file->getMimeType();
            $doc->document_size = (int) $file->getSize();
        }

        $doc->id_type = trim((string) $validated['id_type']);
        $doc->id_number = $idNumber;
        $doc->issuing_agency = trim((string) $validated['issuing_agency']);
        $doc->expiry_date = $validated['expiry_date'] ?? null;

        // Admin edit resets to pending unless later verified.
        $doc->status = 'pending';
        $doc->verified_by = null;
        $doc->verified_at = null;
        $doc->rejection_reason = null;
        $doc->save();

        return response()->json([
            'message' => 'Government ID updated.',
            'government_id' => $this->serialize($doc),
        ]);
    }

    public function destroy(Request $request, int $userId, int $id): JsonResponse
    {
        $employee = User::where('id', $userId)->where('role', User::ROLE_EMPLOYEE)->firstOrFail();
        $this->assertEmployeeOrgScope($request, $employee);
        $doc = EmployeeGovernmentIdDocument::where('id', $id)->where('user_id', $employee->id)->firstOrFail();

        if ($doc->document_path) {
            Storage::disk('public')->delete($doc->document_path);
        }
        $doc->delete();

        return response()->json(['message' => 'Government ID deleted.']);
    }

    public function verify(Request $request, int $userId, int $id): JsonResponse
    {
        $employee = User::where('id', $userId)->where('role', User::ROLE_EMPLOYEE)->firstOrFail();
        $this->assertEmployeeOrgScope($request, $employee);
        $doc = EmployeeGovernmentIdDocument::where('id', $id)->where('user_id', $employee->id)->firstOrFail();

        $validated = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'rejection_reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validated['status'] === 'rejected' && trim((string) ($validated['rejection_reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['rejection_reason' => ['Rejection reason is required when rejecting.']]);
        }

        $doc->status = $validated['status'];
        $doc->verified_by = (int) $request->user()->id;
        $doc->verified_at = now();
        $doc->rejection_reason = $validated['status'] === 'rejected' ? trim((string) $validated['rejection_reason']) : null;
        $doc->save();

        return response()->json([
            'message' => 'Verification updated.',
            'government_id' => $this->serialize($doc),
        ]);
    }
}
