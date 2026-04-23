<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AssertsEmployeeOrgScope;
use App\Http\Controllers\Controller;
use App\Models\EmployeeGovernmentIdDocument;
use App\Models\User;
use App\Services\GovernmentIdFormatter;
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

    /**
     * Auto-format SSS / PhilHealth / Pag-IBIG / TIN input into the canonical
     * dashed form ({@see GovernmentIdFormatter}) so manual uploads match the
     * display mask used in the Gov IDs tab and in bulk imports.
     */
    private function normalizeIdNumberForType(string $type, string $value): string
    {
        $canon = GovernmentIdFormatter::canonicalType($type);
        if ($canon !== null) {
            $formatted = GovernmentIdFormatter::format($canon, $value);
            if ($formatted !== null) {
                return $formatted;
            }
        }

        return trim($value);
    }

    private function validateIdNumberByType(string $type, ?string $value): void
    {
        $v = $value ? trim($value) : '';
        if ($v === '') {
            throw ValidationException::withMessages(['id_number' => ['ID number is required.']]);
        }

        $canon = GovernmentIdFormatter::canonicalType($type);
        if ($canon !== null && ! GovernmentIdFormatter::isValidFormatted($canon, $v)) {
            throw ValidationException::withMessages([
                'id_number' => [GovernmentIdFormatter::formatHint($canon) ?? 'Invalid ID number format.'],
            ]);
        }
    }

    public function index(Request $request, int $userId): JsonResponse
    {
        $employee = User::where('id', $userId)->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)->firstOrFail();
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
        $employee = User::where('id', $userId)->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)->firstOrFail();
        $this->assertEmployeeOrgScope($request, $employee);

        $validated = $request->validate([
            'id_type' => ['required', 'string', 'max:60'],
            'id_number' => ['required', 'string', 'max:120'],
            'issuing_agency' => ['required', 'string', 'max:180'],
            'expiry_date' => ['nullable', 'date'],
            'document_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $idType = trim((string) $validated['id_type']);
        $idNumber = $this->normalizeIdNumberForType($idType, (string) $validated['id_number']);
        $this->validateIdNumberByType($idType, $idNumber);

        $exists = EmployeeGovernmentIdDocument::where('user_id', $employee->id)->whereRaw('LOWER(id_number) = ?', [mb_strtolower($idNumber)])->exists();
        if ($exists) {
            throw ValidationException::withMessages(['id_number' => ['Duplicate ID number for this employee.']]);
        }

        $file = $request->file('document_file');
        $path = $file->store('government-ids', 'public');
        $mime = $file->getClientMimeType() ?: $file->getMimeType();
        $size = (int) $file->getSize();

        // Admin-submitted uploads are auto-approved on create; the admin is the verifier.
        $doc = EmployeeGovernmentIdDocument::create([
            'user_id' => $employee->id,
            'id_type' => $idType,
            'id_number' => $idNumber,
            'issuing_agency' => trim((string) $validated['issuing_agency']),
            'expiry_date' => $validated['expiry_date'] ?? null,
            'document_path' => $path,
            'document_mime' => $mime,
            'document_size' => $size,
            'status' => 'approved',
            'verified_by' => (int) $request->user()->id,
            'verified_at' => now(),
            'rejection_reason' => null,
        ]);

        return response()->json([
            'message' => 'Government ID uploaded.',
            'government_id' => $this->serialize($doc),
        ], 201);
    }

    public function update(Request $request, int $userId, int $id): JsonResponse
    {
        $employee = User::where('id', $userId)->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)->firstOrFail();
        $this->assertEmployeeOrgScope($request, $employee);
        $doc = EmployeeGovernmentIdDocument::where('id', $id)->where('user_id', $employee->id)->firstOrFail();

        $validated = $request->validate([
            'id_type' => ['required', 'string', 'max:60'],
            'id_number' => ['required', 'string', 'max:120'],
            'issuing_agency' => ['required', 'string', 'max:180'],
            'expiry_date' => ['nullable', 'date'],
            'document_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $idType = trim((string) $validated['id_type']);
        $idNumber = $this->normalizeIdNumberForType($idType, (string) $validated['id_number']);
        $this->validateIdNumberByType($idType, $idNumber);

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

        $doc->id_type = $idType;
        $doc->id_number = $idNumber;
        $doc->issuing_agency = trim((string) $validated['issuing_agency']);
        $doc->expiry_date = $validated['expiry_date'] ?? null;

        // Admin edits stay auto-approved; the editing admin is the new verifier.
        $doc->status = 'approved';
        $doc->verified_by = (int) $request->user()->id;
        $doc->verified_at = now();
        $doc->rejection_reason = null;
        $doc->save();

        return response()->json([
            'message' => 'Government ID updated.',
            'government_id' => $this->serialize($doc),
        ]);
    }

    public function destroy(Request $request, int $userId, int $id): JsonResponse
    {
        $employee = User::where('id', $userId)->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)->firstOrFail();
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
        $employee = User::where('id', $userId)->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)->firstOrFail();
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
