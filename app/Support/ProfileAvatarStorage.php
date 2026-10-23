<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProfileAvatarStorage
{
    public static function validate(Request $request): void
    {
        $request->validate([
            'avatar' => ['nullable', 'image', 'max:4096'],
            'remove_avatar' => ['sometimes', 'accepted'],
        ]);

        if (! $request->hasFile('avatar') && ! $request->boolean('remove_avatar')) {
            throw ValidationException::withMessages([
                'avatar' => ['Unggah foto baru atau minta penghapusan foto profil.'],
            ]);
        }
    }

    public static function update(Request $request, ?string $currentPath): ?string
    {
        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('uploads/avatars', 'public');
            if (! is_string($path)) {
                throw new \RuntimeException('Gagal menyimpan foto profil.');
            }
            self::deletePrevious($currentPath, $path);

            return $path;
        }

        if ($request->boolean('remove_avatar')) {
            self::deletePrevious($currentPath);

            return null;
        }

        return $currentPath;
    }

    private static function deletePrevious(?string $path, ?string $replacement = null): void
    {
        if (
            $path !== null
            && $path !== $replacement
            && str_starts_with($path, 'uploads/avatars/')
        ) {
            Storage::disk('public')->delete($path);
        }
    }
}
