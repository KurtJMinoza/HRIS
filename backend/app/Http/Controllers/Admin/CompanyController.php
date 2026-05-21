<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HrRole;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\HrRoleResolver;
use App\Support\EmployeeProfileCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CompanyController extends Controller
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly HrRoleResolver $hrRoleResolver,
    ) {}

    private const LOGO_DISK = 'public';

    private const LOGO_DIR = 'company-logos';

    private const LOGO_MAX_KB = 2048;

    private const LOGO_MIMES = ['jpeg', 'jpg', 'png', 'webp'];

    /**
     * List all companies with logo, head, branch/department/employee counts.
     * Uses a single aggregated query — no N+1 per row.
     */
    public function index(Request $request): JsonResponse
    {
        $role = $this->hrRoleResolver->resolve($request->user());
        if ($role === HrRole::BranchHead || $role === HrRole::DepartmentHead) {
            abort(403, 'The Company module is not available for your role.');
        }

        // total_employees: count employees via company_id, branch, or department (employees may be assigned to branch/dept without direct company_id).
        // Exclude users who are Company Head of another company — they belong to that company only.
        $totalEmployeesSub = DB::table('users')
            ->whereIn('users.role', User::ROSTER_ELIGIBLE_ROLES)
            ->where(function ($q) {
                $q->whereColumn('users.company_id', 'companies.id')
                    ->orWhereExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('branches')
                            ->whereColumn('branches.id', 'users.branch_id')
                            ->whereColumn('branches.company_id', 'companies.id');
                    })
                    ->orWhereExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('departments')
                            ->join('branches', 'departments.branch_id', '=', 'branches.id')
                            ->whereColumn('departments.id', 'users.department_id')
                            ->whereColumn('branches.company_id', 'companies.id');
                    });
            })
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('companies as head_co')
                    ->whereColumn('head_co.company_head_id', 'users.id')
                    ->whereColumn('head_co.id', '!=', 'companies.id');
            })
            ->selectRaw('COUNT(*)');

        $companiesQuery = Company::query()
            ->select('companies.*')
            ->selectSub($totalEmployeesSub, 'total_employees')
            ->with('companyHead:id,name,first_name,middle_name,last_name,suffix')
            ->withCount(['branches', 'departments as departments_count'])
            ->orderBy('name');

        $this->dataScopeService->restrictCompanyQuery($request->user(), $companiesQuery);

        $companies = $companiesQuery
            ->get()
            ->map(fn (Company $c) => $this->companyResponse($c));

        return response()->json(['companies' => $companies]);
    }

    /**
     * Create a company (name + optional logo + optional company head).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                'unique:companies,name',
                "regex:/^[A-Za-z0-9\s\-']+$/",
            ],
            'company_head_id' => ['nullable', 'integer', 'exists:users,id'],
            'tin' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:5000'],
            'logo' => ['nullable', 'image', 'mimes:'.implode(',', self::LOGO_MIMES), 'max:'.self::LOGO_MAX_KB],
        ], [
            'name.regex' => 'Company name may only contain letters, numbers, spaces, hyphens, and apostrophes.',
            'logo.image' => 'The logo must be an image (JPG, PNG, or WebP).',
            'logo.mimes' => 'The logo must be a JPG, PNG, or WebP file.',
            'logo.max' => 'The logo must not exceed 2MB.',
        ]);

        $this->validateCompanyHeadNotAssignedElsewhere($validated['company_head_id'] ?? null, null);

        $path = $request->hasFile('logo') ? $this->storeLogoOrFail($request) : null;
        $company = Company::create([
            'name' => $validated['name'],
            'logo' => $path,
            'company_head_id' => $validated['company_head_id'] ?? null,
            'tin' => isset($validated['tin']) && $validated['tin'] !== '' ? $validated['tin'] : null,
            'address' => isset($validated['address']) && $validated['address'] !== '' ? $validated['address'] : null,
        ]);

        // Data integrity: Company Head must belong to the company (auto-set employee.company_id).
        if ($company->company_head_id) {
            User::where('id', $company->company_head_id)->update(['company_id' => $company->id]);
        }

        return response()->json([
            'message' => 'Company created successfully.',
            'company' => $this->companyResponse($company),
        ], 201);
    }

    /**
     * List branches under this company.
     */
    public function branches(int $id): JsonResponse
    {
        $company = Company::findOrFail($id);
        $branches = $company->branches()
            ->with('branchManager:id,name,first_name,middle_name,last_name,suffix,profile_image')
            ->withCount('departments')
            ->withTotalEmployeesCount()
            ->orderBy('name')
            ->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'company_id' => $company->id,
                'company_name' => $company->name,
                'company_logo_url' => $this->publicMediaUrl($company->logo),
                'logo_url' => $this->publicMediaUrl($company->logo),
                'address' => $b->address,
                'branch_manager_id' => $b->branch_manager_id,
                'branch_manager_name' => $b->branchManager?->display_name,
                'branch_manager_profile_image' => $b->branchManager?->profile_image_url ?? null,
                'departments_count' => $b->departments_count,
                'employees_count' => $b->employees_count,
            ]);

        return response()->json([
            'company' => ['id' => $company->id, 'name' => $company->name],
            'branches' => $branches,
        ]);
    }

    /**
     * Update company (name, company head, and/or logo).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $company = Company::findOrFail($id);

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:255',
                'unique:companies,name,'.$id,
                "regex:/^[A-Za-z0-9\s\-']+$/",
            ],
            'company_head_id' => ['nullable', 'integer', 'exists:users,id'],
            'logo' => ['nullable', 'image', 'mimes:'.implode(',', self::LOGO_MIMES), 'max:'.self::LOGO_MAX_KB],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'tin' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:5000'],
            'founded_at' => ['nullable', 'date'],
        ], [
            'name.regex' => 'Company name may only contain letters, numbers, spaces, hyphens, and apostrophes.',
            'logo.image' => 'The logo must be an image (JPG, PNG, or WebP).',
            'logo.mimes' => 'The logo must be a JPG, PNG, or WebP file.',
            'logo.max' => 'The logo must not exceed 2MB.',
        ]);

        if (isset($validated['name'])) {
            $company->name = $validated['name'];
            $company->save();
        }

        if (array_key_exists('company_head_id', $validated)) {
            $previousHeadId = $company->company_head_id;
            $this->validateCompanyHeadNotAssignedElsewhere($validated['company_head_id'] ?? null, $id);
            $company->company_head_id = $validated['company_head_id'];
            $company->save();

            // Data integrity: Company Head must belong to the company (auto-set employee.company_id).
            if ($company->company_head_id) {
                User::where('id', $company->company_head_id)->update(['company_id' => $company->id]);
            }
            foreach (array_unique(array_filter([
                $previousHeadId ? (int) $previousHeadId : null,
                $company->company_head_id ? (int) $company->company_head_id : null,
            ])) as $uid) {
                EmployeeProfileCache::invalidate($uid);
            }
        }

        if ($request->hasFile('logo')) {
            if ($company->logo && Storage::disk(self::LOGO_DISK)->exists($company->logo)) {
                Storage::disk(self::LOGO_DISK)->delete($company->logo);
            }
            $company->logo = $this->storeLogoOrFail($request);
            $company->save();
        }

        $profileUpdated = false;
        foreach (['phone', 'email', 'tin', 'address'] as $key) {
            if (array_key_exists($key, $validated)) {
                $company->{$key} = $validated[$key] !== '' ? $validated[$key] : null;
                $profileUpdated = true;
            }
        }
        if (array_key_exists('founded_at', $validated)) {
            $company->founded_at = $validated['founded_at'];
            $profileUpdated = true;
        }
        if ($profileUpdated) {
            $company->save();
        }

        return response()->json([
            'message' => 'Company updated successfully.',
            'company' => $this->companyResponse($company->fresh(['companyHead:id,name,first_name,middle_name,last_name,suffix'])),
        ]);
    }

    /**
     * Update company profile fields (contact, TIN, logo) — company head only, scoped to their company.
     */
    public function updateProfile(Request $request, int $id): JsonResponse
    {
        $company = Company::findOrFail($id);
        $actor = $request->user();

        if ((int) $company->company_head_id !== (int) $actor->id) {
            abort(403, 'Only the assigned company head can update this company profile.');
        }

        $validated = $request->validate([
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'tin' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:5000'],
            'founded_at' => ['nullable', 'date'],
            'logo' => ['nullable', 'image', 'mimes:'.implode(',', self::LOGO_MIMES), 'max:'.self::LOGO_MAX_KB],
        ], [
            'logo.image' => 'The logo must be an image (JPG, PNG, or WebP).',
            'logo.mimes' => 'The logo must be a JPG, PNG, or WebP file.',
            'logo.max' => 'The logo must not exceed 2MB.',
        ]);

        foreach (['phone', 'email', 'tin', 'address'] as $key) {
            if (array_key_exists($key, $validated)) {
                $company->{$key} = $validated[$key] !== '' ? $validated[$key] : null;
            }
        }
        if (array_key_exists('founded_at', $validated)) {
            $company->founded_at = $validated['founded_at'];
        }

        if ($request->hasFile('logo')) {
            if ($company->logo && Storage::disk(self::LOGO_DISK)->exists($company->logo)) {
                Storage::disk(self::LOGO_DISK)->delete($company->logo);
            }
            $company->logo = $this->storeLogoOrFail($request);
        }

        $company->save();

        return response()->json([
            'message' => 'Company profile updated successfully.',
            'company' => $this->companyResponse($company->fresh(['companyHead:id,name,first_name,middle_name,last_name,suffix'])),
        ]);
    }

    /**
     * Delete a company. Blocked if it has branches.
     */
    public function destroy(int $id): JsonResponse
    {
        $company = Company::findOrFail($id);

        if ($company->branches()->exists()) {
            return response()->json([
                'message' => 'Cannot delete company because it has branches. Remove or reassign branches first.',
            ], 422);
        }

        if ($company->logo && Storage::disk(self::LOGO_DISK)->exists($company->logo)) {
            Storage::disk(self::LOGO_DISK)->delete($company->logo);
        }
        User::where('company_id', $id)->update(['company_id' => null]);
        $company->delete();

        return response()->json(['message' => 'Company deleted successfully.']);
    }

    private function companyResponse(Company $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'logo' => $c->logo,
            'logo_url' => $this->publicMediaUrl($c->logo),
            'company_head_id' => $c->company_head_id,
            'company_head_name' => $c->companyHead?->display_name,
            'phone' => $c->phone,
            'email' => $c->email,
            'tin' => $c->tin,
            'address' => $c->address,
            'founded_at' => $c->founded_at?->format('Y-m-d'),
            'branches_count' => $c->branches_count ?? 0,
            'departments_count' => $c->departments_count ?? 0,
            'total_employees' => $c->total_employees ?? 0,
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }

    private function encodeStoragePath(string $path): string
    {
        $segments = explode('/', trim($path, '/'));
        $encoded = array_map(static fn (string $segment) => rawurlencode($segment), $segments);

        return implode('/', $encoded);
    }

    private function publicMediaUrl(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }
        $normalized = trim($path);
        if (str_starts_with($normalized, 'http://') || str_starts_with($normalized, 'https://')) {
            return $normalized;
        }
        $normalized = ltrim($normalized, '/');
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = ltrim(substr($normalized, strlen('storage/')), '/');
        }

        // Skip Storage::exists() check on list endpoints — it's a filesystem I/O call per row.
        // The frontend gracefully handles broken image URLs with avatar fallbacks.
        return '/api/media/public/'.$this->encodeStoragePath($normalized);
    }

    private function storeLogoOrFail(Request $request): string
    {
        $file = $request->file('logo');
        if (! $file) {
            throw ValidationException::withMessages(['logo' => ['No logo file was uploaded.']]);
        }
        Storage::disk(self::LOGO_DISK)->makeDirectory(self::LOGO_DIR);
        $path = Storage::disk(self::LOGO_DISK)->putFile(self::LOGO_DIR, $file);
        if (! is_string($path) || trim($path) === '') {
            throw ValidationException::withMessages([
                'logo' => ['Failed to store company logo. Please check storage permissions and try again.'],
            ]);
        }

        return $path;
    }

    /**
     * Ensure the given user is not already assigned as Company Head of another company.
     * When assigning to an existing company (excludeCompanyId), that company is excluded from the check.
     */
    private function validateCompanyHeadNotAssignedElsewhere(?int $userId, ?int $excludeCompanyId): void
    {
        if ($userId === null) {
            return;
        }

        $query = Company::where('company_head_id', $userId);
        if ($excludeCompanyId !== null) {
            $query->where('id', '!=', $excludeCompanyId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'company_head_id' => ['This employee is already assigned as Company Head of another company. One employee cannot lead multiple companies.'],
            ]);
        }
    }
}
