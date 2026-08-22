<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AvatarController extends BaseApiController
{
    /**
     * Allowed MIME types for avatar uploads.
     */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
    ];

    /**
     * Maximum file size in kilobytes (5MB).
     */
    private const MAX_FILE_SIZE_KB = 5120;

    /**
     * Upload or replace the authenticated user's profile photo.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => [
                'required',
                'file',
                'max:' . self::MAX_FILE_SIZE_KB,
                function ($attribute, $value, $fail) {
                    if (!in_array($value->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
                        $fail('The avatar must be a JPG, PNG, or WEBP image.');
                    }
                    $extension = strtolower($value->getClientOriginalExtension());
                    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                        $fail('The avatar file extension must be jpg, jpeg, png, or webp.');
                    }
                },
            ],
        ]);

        $user = $request->user();

        // Remove old avatar if exists
        if ($user->avatar) {
            $oldPath = str_replace('/storage/', '', parse_url($user->avatar, PHP_URL_PATH) ?? $user->avatar);
            if (Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        $file = $request->file('avatar');
        $fileName = $user->id . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $file->getClientOriginalExtension();
        $file->storeAs('avatars', $fileName, 'public');

        $url = Storage::disk('public')->url('avatars/' . $fileName);

        $user->update(['avatar' => $url]);

        return $this->sendResponse([
            'avatar' => $url,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => $url,
            ],
        ], 'Profile photo uploaded successfully.');
    }

    /**
     * Remove the authenticated user's profile photo.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->avatar) {
            return $this->sendError('No profile photo to remove.', [], 404);
        }

        $path = str_replace('/storage/', '', parse_url($user->avatar, PHP_URL_PATH) ?? $user->avatar);
        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        $user->update(['avatar' => null]);

        return $this->sendResponse([
            'avatar' => null,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => null,
            ],
        ], 'Profile photo removed successfully.');
    }
}
