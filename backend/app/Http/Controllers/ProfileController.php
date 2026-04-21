<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserAdminActivityLog;
use App\Jobs\ProcessFaceRegistrationJob;
use App\Services\FaceRegistrationStatusService;
use App\Services\RbacService;
use App\Services\RekognitionLivenessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function __construct(
        private readonly RbacService $rbacService,
    ) {}

    /**
     * Update profile: email, phone, and/or password.
     * Sensitive changes (email, phone_number, password) require identity verification via Rekognition Face Liveness.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $this->canEditOwnProfile($user)) {
            return response()->json(['message' => 'Profile details can only be edited by HR.'], 403);
        }

        $rules = [];
        $data = [];
        $messages = [];

        $sensitiveChange = $request->filled('email')
            || $request->filled('username')
            || $request->has('phone_number')
            || $request->filled('password');

        if ($sensitiveChange) {
            $request->validate([
                'liveness_session_id' => ['required', 'string', 'max:255'],
            ], [
                'liveness_session_id.required' => 'Identity verification is required. Complete the face liveness check before updating.',
            ]);
            $sessionId = $request->input('liveness_session_id');
            $livenessResult = RekognitionLivenessService::getSessionResults($sessionId);
            if ($livenessResult === null || ($livenessResult['result'] ?? '') !== 'PASS') {
                throw ValidationException::withMessages([
                    'liveness_session_id' => ['Identity verification failed. Please complete the face liveness check again.'],
                ]);
            }
        }

        if ($request->filled('name')) {
            $rules['name'] = ['required', 'string', 'max:255', "regex:/^[A-Za-z0-9\\s\\-']+$/"];
            $data['name'] = trim((string) $request->input('name'));
            $messages['name.regex'] = 'Name may only contain letters, numbers, spaces, hyphens, and apostrophes.';
        }

        if ($request->filled('email')) {
            $rules['email'] = ['required', 'string', 'email', 'max:255', 'unique:users,email,'.$user->id];
            $data['email'] = $request->input('email');
        }
        if ($request->filled('username')) {
            $rules['username'] = ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._]+$/', 'unique:users,username,'.$user->id];
            $data['username'] = trim((string) $request->input('username'));
        }

        if ($request->has('phone_number')) {
            $raw = $request->input('phone_number');
            $rules['phone_number'] = [
                'nullable',
                'string',
                'regex:/^\+63\s?9\d{9}$/u',
                'unique:users,phone_number,'.$user->id,
            ];
            $data['phone_number'] = is_string($raw) && trim($raw) !== '' ? \App\Services\SmsService::normalizePhone($raw) : null;
            $messages['phone_number.regex'] = 'The phone number must start with +63 and be followed by exactly 10 digits (e.g. +63 912 345 6789).';
            $messages['phone_number.unique'] = 'This phone number is already in use by another account.';
        }

        if ($request->filled('password')) {
            $rules['current_password'] = ['required', 'string'];
            $rules['password'] = ['required', 'string', 'min:8', 'confirmed'];
            $data['password'] = Hash::make($request->input('password'));
        }

        if (empty($rules)) {
            return response()->json(['user' => $this->userResponse($user)]);
        }

        $validated = $request->validate($rules, $messages);

        if (isset($validated['current_password']) && ! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->fill($data);
        $user->save();

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $this->userResponse($user),
        ]);
    }

    /**
     * Upload profile photo.
     */
    public function uploadPhoto(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:2048'],
        ]);

        $user = $request->user();
        if (! $user || ! ($this->rbacService->can($user, 'profile.picture.edit') || $this->canEditOwnProfile($user))) {
            return response()->json(['message' => 'You are not allowed to edit profile picture.'], 403);
        }

        if ($user->profile_image) {
            Storage::disk('public')->delete($user->profile_image);
        }

        $path = $request->file('photo')->store('profiles', 'public');
        $user->profile_image = $path;
        $user->save();

        return response()->json([
            'message' => 'Profile photo updated successfully.',
            'user' => $this->userResponse($user),
        ]);
    }

    /**
     * Remove profile photo.
     */
    public function removePhoto(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! ($this->rbacService->can($user, 'profile.picture.edit') || $this->canEditOwnProfile($user))) {
            return response()->json(['message' => 'You are not allowed to edit profile picture.'], 403);
        }

        if ($user->profile_image) {
            Storage::disk('public')->delete($user->profile_image);
            $user->profile_image = null;
            $user->save();
        }

        return response()->json([
            'message' => 'Profile photo removed.',
            'user' => $this->userResponse($user),
        ]);
    }

    /**
     * Get the authenticated user's QR token for display/download.
     * Self-service (employees, org heads, HR admin). Reuses same generation logic as Admin → Employee.
     * If no QR token exists, generates one (single source of truth).
     */
    public function getMyQr(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->canAccessSelfServiceEmployeeProfile()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if (empty($user->qr_token)) {
            $user->update([
                'qr_token' => User::generateQrTokenFor($user),
                'qr_token_generated_at' => now(),
            ]);
        }

        $user->loadMissing([
            'companyHeadships:id,name,logo,company_head_id',
            'company:id,name,logo',
            'branch.company:id,name,logo',
            'departmentRelation.branch.company:id,name,logo',
        ]);
        $effectiveCompany = $user->companyHeadships->first()
            ?? $user->company
            ?? $user->branch?->company
            ?? $user->departmentRelation?->branch?->company;
        $companyLogoUrl = $effectiveCompany?->logo ? $this->companyLogoUrlForQr($effectiveCompany->logo) : null;

        return response()->json([
            'employee_id' => $user->id,
            'employee_name' => $user->name,
            'qr_token' => $user->qr_token,
            'qr_token_generated_at' => $user->qr_token_generated_at?->toIso8601String(),
            'has_qr' => true,
            'company_logo_url' => $companyLogoUrl,
        ]);
    }

    /**
     * Regenerate the authenticated user's QR token (invalidates previous QR).
     * Self-service (employees, org heads, HR admin). Reuses same logic as Admin → Employee regenerate.
     */
    public function regenerateMyQr(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->canAccessSelfServiceEmployeeProfile()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $user->update([
            'qr_token' => User::generateQrTokenFor($user),
            'qr_token_generated_at' => now(),
        ]);

        $user->loadMissing([
            'companyHeadships:id,name,logo,company_head_id',
            'company:id,name,logo',
            'branch.company:id,name,logo',
            'departmentRelation.branch.company:id,name,logo',
        ]);
        $effectiveCompany = $user->companyHeadships->first()
            ?? $user->company
            ?? $user->branch?->company
            ?? $user->departmentRelation?->branch?->company;
        $companyLogoUrl = $effectiveCompany?->logo ? $this->companyLogoUrlForQr($effectiveCompany->logo) : null;

        return response()->json([
            'message' => 'QR code regenerated. Use the new code for attendance scanning.',
            'employee_id' => $user->id,
            'employee_name' => $user->name,
            'qr_token' => $user->qr_token,
            'qr_token_generated_at' => $user->qr_token_generated_at?->toIso8601String(),
            'has_qr' => true,
            'company_logo_url' => $companyLogoUrl,
        ]);
    }

    /**
     * Register face for the authenticated user. Same flow as admin registerFace.
     * Self-service (employees, org heads, HR admin); own record only.
     * Embedding extraction and persistence run in {@see ProcessFaceRegistrationJob} (queue) with duplicate checks under lock.
     */
    public function registerMyFace(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->canAccessSelfServiceEmployeeProfile()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            'liveness_session_id' => ['nullable', 'string', 'max:255'],
            'image_base64' => ['nullable', 'string'],
            'liveness_type' => ['nullable', 'string', 'in:rekognition,mediapipe,hybrid'],
        ]);
        $sessionId = $validated['liveness_session_id'] ?? null;
        $imageBase64 = $validated['image_base64'] ?? null;
        if (! $sessionId && ! $imageBase64) {
            return response()->json([
                'message' => 'Perform face liveness first or provide a face image.',
                'errors' => ['face' => ['Face liveness session or face image is required.']],
                'error_code' => 'validation_error',
            ], 422);
        }

        $trackId = (string) Str::uuid();
        FaceRegistrationStatusService::create($trackId, ['target_user_id' => $user->id]);

        ProcessFaceRegistrationJob::dispatch(
            $trackId,
            $user->id,
            $sessionId,
            $imageBase64,
            $validated['liveness_type'] ?? 'rekognition',
            $user->id,
            $request->ip(),
            $request->userAgent(),
            'self_service',
        )->onQueue('face-registration');

        return $this->faceRegistrationHttpResponse($request, $trackId, $user->id, true);
    }

    /**
     * Poll async face registration status (self-service: target user must match authenticated user).
     */
    public function faceRegistrationStatus(Request $request, string $trackId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->canAccessSelfServiceEmployeeProfile()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return $this->faceRegistrationHttpResponse($request, $trackId, $user->id, false);
    }

    /**
     * @param  bool  $isInitialPost  When true, return 202 for in-progress; when false (GET poll), return 200 for in-progress.
     */
    private function faceRegistrationHttpResponse(Request $request, string $trackId, int $expectedUserId, bool $isInitialPost): JsonResponse
    {
        $row = FaceRegistrationStatusService::get($trackId);
        if ($row === null) {
            return response()->json(['message' => 'Unknown or expired face registration request.'], 404);
        }
        if ((int) ($row['target_user_id'] ?? 0) !== $expectedUserId) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $status = $row['status'] ?? 'pending';
        if ($status === 'completed') {
            $target = User::query()->find($expectedUserId);

            return response()->json([
                'status' => 'completed',
                'message' => 'Face registered successfully. You can now use Facial Recognition for DTR clock-in and clock-out.',
                'user' => $target ? $this->userResponse($target->fresh()) : null,
            ]);
        }
        if ($status === 'failed') {
            $msg = $row['message'] ?? 'Face registration failed.';

            return response()->json([
                'status' => 'failed',
                'message' => $msg,
                'errors' => ['face' => [$msg]],
                'error_code' => $row['error_code'] ?? 'registration_failed',
            ], 422);
        }

        return response()->json([
            'status' => $status,
            'message' => 'Processing face…',
            'track_id' => $trackId,
        ], $isInitialPost ? 202 : 200);
    }

    /**
     * Get the authenticated user's registered face image. Self-service; own record only.
     */
    public function getMyFace(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->canAccessSelfServiceEmployeeProfile()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $hasFace = $user->hasRegisteredFace();

        if (! $hasFace || empty($user->face_image)) {
            return response()->json([
                'has_face' => false,
                'face_image' => null,
                'message' => 'No face registered.',
            ]);
        }

        $img = $user->face_image;
        $dataUrl = is_string($img) && (str_starts_with($img, 'data:') || preg_match('/^[A-Za-z0-9+\/=]+$/', $img))
            ? (str_starts_with($img, 'data:') ? $img : 'data:image/jpeg;base64,'.$img)
            : null;

        return response()->json([
            'has_face' => true,
            'face_image' => $dataUrl,
        ]);
    }

    /**
     * Remove face for the authenticated user. Self-service; own record only.
     */
    public function removeMyFace(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->canAccessSelfServiceEmployeeProfile()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $user->clearFaceRegistrationData($user->id);

        return response()->json([
            'message' => 'Face removed. Facial Recognition will be disabled until you register again.',
            'user' => $this->userResponse($user->fresh()),
        ]);
    }

    private function companyLogoUrlForQr(string $path): string
    {
        $normalized = ltrim(trim($path), '/');
        if (str_starts_with($normalized, 'http://') || str_starts_with($normalized, 'https://')) {
            return $normalized;
        }
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = ltrim(substr($normalized, strlen('storage/')), '/');
        }
        $segments = explode('/', $normalized);
        $encoded = array_map(static fn (string $s) => rawurlencode($s), $segments);

        return url('/api/media/public/'.implode('/', $encoded));
    }

    private function userResponse($user): array
    {
        return (new AuthController)->userResponse($user);
    }

    private function canEditOwnProfile(User $user): bool
    {
        $hrRole = strtolower(trim((string) ($user->hr_role ?? '')));

        return $user->isAdmin()
            || $hrRole === 'admin_hr'
            || $hrRole === 'admin'
            || $this->rbacService->can($user, 'profile.edit')
            || $this->rbacService->can($user, 'edit-own-profile');
    }
}
