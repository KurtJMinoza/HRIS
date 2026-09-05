<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AssertsEmployeeOrgScope;
use App\Http\Controllers\Controller;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeGovernmentId;
use App\Models\EmployeeGovernmentIdDocument;
use App\Models\User;
use App\Services\BankAccountFormatter;
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
        $employee = User::where('id', $userId)->visibleEmployees()->firstOrFail();
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
        $employee = User::where('id', $userId)->visibleEmployees()->firstOrFail();
        $this->assertEmployeeOrgScope($request, $employee);

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

        $exists = EmployeeGovernmentIdDocument::where('user_id', $employee->id)->whereRaw('LOWER(id_number) = ?', [mb_strtolower($idNumber)])->exists();
        if ($exists) {
            throw ValidationException::withMessages(['id_number' => ['Duplicate ID number for this employee.']]);
        }

        $path = null;
        $mime = null;
        $size = 0;
        if ($request->hasFile('document_file')) {
            $file = $request->file('document_file');
            $path = $file->store('government-ids', 'public');
            $mime = $file->getClientMimeType() ?: $file->getMimeType();
            $size = (int) $file->getSize();
        }

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

        $this->syncRegistryFromDocument((int) $employee->id, $idType, $idNumber);

        return response()->json([
            'message' => 'Government ID saved.',
            'government_id' => $this->serialize($doc),
        ], 201);
    }

    public function update(Request $request, int $userId, int $id): JsonResponse
    {
        $employee = User::where('id', $userId)->visibleEmployees()->firstOrFail();
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

        $this->syncRegistryFromDocument((int) $employee->id, $idType, $idNumber);

        return response()->json([
            'message' => 'Government ID updated.',
            'government_id' => $this->serialize($doc),
        ]);
    }

    /**
     * Upsert SSS / PhilHealth / Pag-IBIG / TIN numbers used by payroll missing-info checks.
     * Picture/document is optional — numbers alone are enough for Employee Deductions.
     */
    public function upsertNumbers(Request $request, int $userId): JsonResponse
    {
        $employee = User::where('id', $userId)->visibleEmployees()->firstOrFail();
        $this->assertEmployeeOrgScope($request, $employee);

        $validated = $request->validate([
            'sss_number' => ['nullable', 'string', 'max:30', 'regex:/^\d{2}-\d{7}-\d{1}$/u'],
            'philhealth_number' => ['nullable', 'string', 'max:30', 'regex:/^\d{2}-\d{9}-\d{1}$/u'],
            'pagibig_number' => ['nullable', 'string', 'max:30', 'regex:/^\d{4}-\d{4}-\d{4}$/u'],
            'tin_number' => ['nullable', 'string', 'max:30', 'regex:/^\d{3}-\d{3}-\d{3}-\d{3}$/u'],
        ], [
            'tin_number.regex' => 'TIN must use format 000-000-000-000.',
            'sss_number.regex' => 'SSS Number must use format 00-0000000-0.',
            'philhealth_number.regex' => 'PhilHealth Number must use format 00-000000000-0.',
            'pagibig_number.regex' => 'Pag-IBIG Number must use format 0000-0000-0000.',
        ]);

        $record = EmployeeGovernmentId::query()->firstOrNew(['user_id' => (int) $employee->id]);
        foreach (['sss_number', 'philhealth_number', 'pagibig_number', 'tin_number'] as $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }
            $value = trim((string) ($validated[$field] ?? ''));
            $record->{$field} = $value !== '' ? $value : null;
        }
        $record->save();

        $actorId = (int) $request->user()->id;
        $this->mirrorDocumentRow((int) $employee->id, GovernmentIdFormatter::TYPE_SSS, $record->sss_number, $actorId);
        $this->mirrorDocumentRow((int) $employee->id, GovernmentIdFormatter::TYPE_PHILHEALTH, $record->philhealth_number, $actorId);
        $this->mirrorDocumentRow((int) $employee->id, GovernmentIdFormatter::TYPE_PAGIBIG, $record->pagibig_number, $actorId);
        $this->mirrorDocumentRow((int) $employee->id, GovernmentIdFormatter::TYPE_TIN, $record->tin_number, $actorId);

        return response()->json([
            'message' => 'Government IDs updated.',
            'government_ids' => [
                'sss_number' => $record->sss_number,
                'philhealth_number' => $record->philhealth_number,
                'pagibig_number' => $record->pagibig_number,
                'tin_number' => $record->tin_number,
            ],
        ]);
    }

    public function upsertBankAccount(Request $request, int $userId): JsonResponse
    {
        $employee = User::where('id', $userId)->visibleEmployees()->firstOrFail();
        $this->assertEmployeeOrgScope($request, $employee);

        $normalized = BankAccountFormatter::validateAndNormalize($request->all());
        $record = EmployeeBankAccount::query()->firstOrNew(['user_id' => (int) $employee->id]);
        $record->fill($normalized);
        $record->save();

        return response()->json([
            'message' => 'Bank account updated.',
            'bank_account' => BankAccountFormatter::serialize($record),
        ]);
    }

    private function syncRegistryFromDocument(int $userId, string $idType, string $idNumber): void
    {
        $field = GovernmentIdFormatter::registryFieldForType($idType);
        if ($field === null) {
            return;
        }

        $record = EmployeeGovernmentId::query()->firstOrNew(['user_id' => $userId]);
        $record->{$field} = $idNumber;
        $record->save();
    }

    private function mirrorDocumentRow(int $userId, string $idType, ?string $idNumber, int $actorId): void
    {
        $idNumber = is_string($idNumber) ? trim($idNumber) : '';
        if ($idNumber === '' || ! GovernmentIdFormatter::isValidFormatted($idType, $idNumber)) {
            return;
        }

        try {
            EmployeeGovernmentIdDocument::query()->updateOrCreate(
                [
                    'user_id' => $userId,
                    'id_type' => $idType,
                ],
                [
                    'id_number' => $idNumber,
                    'issuing_agency' => GovernmentIdFormatter::agencyFor($idType) ?? '—',
                    'status' => 'approved',
                    'verified_by' => $actorId,
                    'verified_at' => now(),
                    'rejection_reason' => null,
                ]
            );
        } catch (\Throwable) {
            // Unique id_number collision under another type — numbers registry already saved.
        }
    }

    public function destroy(Request $request, int $userId, int $id): JsonResponse
    {
        $employee = User::where('id', $userId)->visibleEmployees()->firstOrFail();
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
        $employee = User::where('id', $userId)->visibleEmployees()->firstOrFail();
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
