<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    /**
     * Update profile: email and/or password.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $rules = [];
        $data = [];

        if ($request->filled('email')) {
            $rules['email'] = ['required', 'string', 'email', 'max:255', 'unique:users,email,' . $user->id];
            $data['email'] = $request->input('email');
        }

        if ($request->filled('password')) {
            $rules['current_password'] = ['required', 'string'];
            $rules['password'] = ['required', 'string', 'min:8', 'confirmed'];
            $data['password'] = Hash::make($request->input('password'));
        }

        if (empty($rules)) {
            return response()->json(['user' => $this->userResponse($user)]);
        }

        $validated = $request->validate($rules);

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

    private function userResponse($user): array
    {
        return (new AuthController())->userResponse($user);
    }
}
