<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileAvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_consumer_role_can_upload_and_remove_a_profile_avatar(): void
    {
        Storage::fake('public');

        $profiles = [
            UserRole::Petani->value => ['/api/farmer/profile/avatar', 'profile.avatar_path'],
            UserRole::Pengepul->value => ['/api/collector/profile/avatar', 'profile.avatar_path'],
            UserRole::Konsumen->value => ['/api/consumer/profile/avatar', 'avatar_path'],
            UserRole::Distributor->value => ['/api/distributor/profile/avatar', 'avatar_path'],
        ];

        foreach ($profiles as $role => [$endpoint, $jsonPath]) {
            $user = User::factory()->create(['role' => $role]);
            $fields = [
                'avatar' => UploadedFile::fake()->createWithContent(
                    'avatar.png',
                    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l8kAAAAASUVORK5CYII='),
                ),
            ];

            $upload = $this->asUser($user)->post($endpoint, $fields);
            $upload->assertOk();

            $avatarPath = $upload->json('data.'.$jsonPath);
            $this->assertStringStartsWith('uploads/avatars/', $avatarPath);
            Storage::disk('public')->assertExists($avatarPath);

            $remove = $this->asUser($user)->post($endpoint, [
                'remove_avatar' => '1',
            ]);
            $remove->assertOk()->assertJsonPath('data.'.$jsonPath, null);
            Storage::disk('public')->assertMissing($avatarPath);
        }
    }

    private function asUser(User $user): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }
}
