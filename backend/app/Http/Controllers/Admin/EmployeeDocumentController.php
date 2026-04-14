<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AssertsEmployeeOrgScope;
use App\Http\Controllers\Controller;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeDocumentController extends Controller
{
    use AssertsEmployeeOrgScope;

    private const CATEGORIES = [
        'Contracts',
        'IDs',
        'Certifications',
        'Disciplinary Records',
        'Medical Documents',
        'Performance Evaluations',
    ];

    private function serialize(EmployeeDocument $d): array
    {
        return [
            'id' => $d->id,
            'user_id' => $d->user_id,
            'category' => $d->category,
            'document_name' => $d->document_name,
            'version' => $d->version,
            'expiry_date' => optional($d->expiry_date)->format('Y-m-d'),
            'status' => $d->status,
            'review_note' => $d->review_note,
            'uploaded_by' => $d->uploaded_by,
            'reviewed_by' => $d->reviewed_by,
            'reviewed_at' => $d->reviewed_at?->toISOString(),
            'file' => [
                'path' => $d->file_path,
                'url' => url('/api/media/public/'.$d->file_path),
                'mime' => $d->file_mime,
                'size' => (int) ($d->file_size ?? 0),
            ],
            'created_at' => $d->created_at?->toISOString(),
            'updated_at' => $d->updated_at?->toISOString(),
        ];
    }

    public function index(Request $request, int $userId): JsonResponse
    {
        $employee = User::where('id', $userId)->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)->firstOrFail();
        $this->assertEmployeeOrgScope($request, $employee);

        $query = EmployeeDocument::where('user_id', $employee->id)->orderByDesc('created_at')->orderByDesc('id');
        $category = trim((string) $request->query('category', ''));
        if ($category !== '') {
            $query->where('category', $category);
        }

        $items = $query->get()->map(fn (EmployeeDocument $d) => $this->serialize($d))->values();

        return response()->json(['documents' => $items]);
    }

    public function store(Request $request, int $userId): JsonResponse
    {
        $employee = User::where('id', $userId)->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)->firstOrFail();
        $this->assertEmployeeOrgScope($request, $employee);

        $validated = $request->validate([
            'category' => ['required', 'string', Rule::in(self::CATEGORIES)],
            'document_name' => ['required', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:30'],
            'expiry_date' => ['nullable', 'date'],
            'file' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,gif,webp', 'max:10240'],
        ]);

        $file = $request->file('file');
        $path = $file->store('employee-documents/'.$employee->id, 'public');
        $mime = $file->getClientMimeType() ?: $file->getMimeType();
        $size = (int) $file->getSize();

        $doc = EmployeeDocument::create([
            'user_id' => $employee->id,
            'category' => trim((string) $validated['category']),
            'document_name' => trim((string) $validated['document_name']),
            'version' => isset($validated['version']) ? trim((string) $validated['version']) : null,
            'expiry_date' => $validated['expiry_date'] ?? null,
            'status' => 'pending',
            'review_note' => null,
            'uploaded_by' => (int) $request->user()->id,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'file_path' => $path,
            'file_mime' => $mime,
            'file_size' => $size,
        ]);

        return response()->json([
            'message' => 'Document uploaded.',
            'document' => $this->serialize($doc),
        ], 201);
    }

    public function update(Request $request, int $userId, int $id): JsonResponse
    {
        $employee = User::where('id', $userId)->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)->firstOrFail();
        $this->assertEmployeeOrgScope($request, $employee);
        $doc = EmployeeDocument::where('id', $id)->where('user_id', $employee->id)->firstOrFail();

        $validated = $request->validate([
            'category' => ['required', 'string', Rule::in(self::CATEGORIES)],
            'document_name' => ['required', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:30'],
            'expiry_date' => ['nullable', 'date'],
            'file' => ['nullable', 'file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,gif,webp', 'max:10240'],
        ]);

        if ($request->hasFile('file')) {
            if ($doc->file_path) {
                Storage::disk('public')->delete($doc->file_path);
            }
            $file = $request->file('file');
            $doc->file_path = $file->store('employee-documents/'.$employee->id, 'public');
            $doc->file_mime = $file->getClientMimeType() ?: $file->getMimeType();
            $doc->file_size = (int) $file->getSize();
        }

        $doc->category = trim((string) $validated['category']);
        $doc->document_name = trim((string) $validated['document_name']);
        $doc->version = isset($validated['version']) ? trim((string) $validated['version']) : null;
        $doc->expiry_date = $validated['expiry_date'] ?? null;

        // Admin edits reset to pending unless immediately reviewed.
        $doc->status = 'pending';
        $doc->review_note = null;
        $doc->reviewed_by = null;
        $doc->reviewed_at = null;
        $doc->save();

        return response()->json([
            'message' => 'Document updated.',
            'document' => $this->serialize($doc),
        ]);
    }

    public function destroy(Request $request, int $userId, int $id): JsonResponse
    {
        $employee = User::where('id', $userId)->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)->firstOrFail();
        $this->assertEmployeeOrgScope($request, $employee);
        $doc = EmployeeDocument::where('id', $id)->where('user_id', $employee->id)->firstOrFail();

        if ($doc->file_path) {
            Storage::disk('public')->delete($doc->file_path);
        }
        $doc->delete();

        return response()->json(['message' => 'Document deleted.']);
    }

    public function review(Request $request, int $userId, int $id): JsonResponse
    {
        $employee = User::where('id', $userId)->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)->firstOrFail();
        $this->assertEmployeeOrgScope($request, $employee);
        $doc = EmployeeDocument::where('id', $id)->where('user_id', $employee->id)->firstOrFail();

        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'archived', 'rejected'])],
            'review_note' => ['nullable', 'string', 'max:800'],
        ]);

        if ($validated['status'] === 'rejected' && trim((string) ($validated['review_note'] ?? '')) === '') {
            throw ValidationException::withMessages(['review_note' => ['A reason is required when rejecting.']]);
        }

        $doc->status = $validated['status'];
        $doc->review_note = isset($validated['review_note']) ? trim((string) $validated['review_note']) : null;
        $doc->reviewed_by = (int) $request->user()->id;
        $doc->reviewed_at = now();
        $doc->save();

        return response()->json([
            'message' => 'Document status updated.',
            'document' => $this->serialize($doc),
        ]);
    }
}
