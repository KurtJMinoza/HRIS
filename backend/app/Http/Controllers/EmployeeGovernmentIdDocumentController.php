<?php

namespace App\Http\Controllers;

use App\Models\EmployeeGovernmentIdDocument;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class EmployeeGovernmentIdDocumentController extends Controller
{
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

    private function normalizeIdNumber(string $value): string
    {
        return trim($value);
    }

    private function validateIdNumberByType(string $type, ?string $value): void
    {
        $v = $value ? trim($value) : '';
        if ($v === '') {
            throw ValidationException::withMessages(['id_number' => ['ID number is required.']]);
        }

        $t = mb_strtolower(trim($type));
        $patterns = [
            'sss' => '/^\d{2}-\d{7}-\d$/u',               // 00-0000000-0
            'tin' => '/^\d{3}-\d{3}-\d{3}$/u',            // 000-000-000
            'philhealth' => '/^\d{4}-\d{4}-\d{4}$/u',     // 0000-0000-0000
            'pag-ibig' => '/^\d{4}-\d{4}-\d{4}$/u',       // 0000-0000-0000
            'pagibig' => '/^\d{4}-\d{4}-\d{4}$/u',
        ];

        if (array_key_exists($t, $patterns) && ! preg_match($patterns[$t], $v)) {
            $msg = match ($t) {
                'sss' => 'SSS format must be 00-0000000-0.',
                'tin' => 'TIN format must be 000-000-000.',
                'philhealth' => 'PhilHealth format must be 0000-0000-0000.',
                'pag-ibig', 'pagibig' => 'Pag-IBIG format must be 0000-0000-0000.',
                default => 'Invalid ID number format.',
            };
            throw ValidationException::withMessages(['id_number' => [$msg]]);
        }
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $items = EmployeeGovernmentIdDocument::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmployeeGovernmentIdDocument $d) => $this->serialize($d))
            ->values();

        return response()->json(['government_ids' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'id_type' => ['required', 'string', 'max:60'],
            'id_number' => ['required', 'string', 'max:120'],
            'issuing_agency' => ['required', 'string', 'max:180'],
            'expiry_date' => ['nullable', 'date'],
            'document_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $this->validateIdNumberByType($validated['id_type'], $validated['id_number']);

        $idNumber = $this->normalizeIdNumber((string) $validated['id_number']);
        $exists = EmployeeGovernmentIdDocument::where('user_id', $user->id)->whereRaw('LOWER(id_number) = ?', [mb_strtolower($idNumber)])->exists();
        if ($exists) {
            throw ValidationException::withMessages(['id_number' => ['Duplicate ID number for this employee.']]);
        }

        $file = $request->file('document_file');
        $path = $file->store('government-ids', 'public');
        $mime = $file->getClientMimeType() ?: $file->getMimeType();
        $size = (int) $file->getSize();

        $doc = EmployeeGovernmentIdDocument::create([
            'user_id' => $user->id,
            'id_type' => trim((string) $validated['id_type']),
            'id_number' => $idNumber,
            'issuing_agency' => trim((string) $validated['issuing_agency']),
            'expiry_date' => $validated['expiry_date'] ?? null,
            'document_path' => $path,
            'document_mime' => $mime,
            'document_size' => $size,
            'status' => 'approved',
            'verified_by' => null,
            'verified_at' => now(),
            'rejection_reason' => null,
        ]);

        return response()->json([
            'message' => 'Government ID uploaded.',
            'government_id' => $this->serialize($doc),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $doc = EmployeeGovernmentIdDocument::where('id', $id)->where('user_id', $user->id)->firstOrFail();

        $validated = $request->validate([
            'id_type' => ['required', 'string', 'max:60'],
            'id_number' => ['required', 'string', 'max:120'],
            'issuing_agency' => ['required', 'string', 'max:180'],
            'expiry_date' => ['nullable', 'date'],
            'document_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $this->validateIdNumberByType($validated['id_type'], $validated['id_number']);

        $idNumber = $this->normalizeIdNumber((string) $validated['id_number']);
        $exists = EmployeeGovernmentIdDocument::where('user_id', $user->id)
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

        $doc->status = 'approved';
        $doc->verified_by = null;
        $doc->verified_at = now();
        $doc->rejection_reason = null;
        $doc->save();

        return response()->json([
            'message' => 'Government ID updated.',
            'government_id' => $this->serialize($doc),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $doc = EmployeeGovernmentIdDocument::where('id', $id)->where('user_id', $user->id)->firstOrFail();

        if ($doc->document_path) {
            Storage::disk('public')->delete($doc->document_path);
        }
        $doc->delete();

        return response()->json(['message' => 'Government ID deleted.']);
    }
}
