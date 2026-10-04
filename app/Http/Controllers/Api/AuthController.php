<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\CollectorProfile;
use App\Models\ConsumerProfile;
use App\Models\DistributorProfile;
use App\Models\FarmerProfile;
use App\Models\UmkmProfile;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{

    /**
     * REGISTER
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'phone' => 'required|string|max:20|unique:users,phone',
            'email' => 'required|email|max:191|unique:users,email',
            'username' => 'nullable|string|max:50|unique:users,username',
            'password' => 'required|min:8|confirmed',
            'role' => ['required', Rule::in(UserRole::values())],
            'village' => 'required|string|max:100',
            'district' => 'required|string|max:100',
            'city' => 'required|string|max:100',
            'province' => 'required|string|max:100',
        ]);

        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'first_name' => trim($data['first_name']),
                'last_name' => trim($data['last_name']),
                'phone' => trim($data['phone']),
                'email' => strtolower(trim($data['email'])),
                'username' => filled($data['username'] ?? null)
                    ? trim($data['username'])
                    : null,
                'password' => $data['password'],
                'role' => $data['role'],
            ]);

            $addressParts = array_map(
                static fn (string $key): string => trim($data[$key]),
                ['village', 'district', 'city', 'province'],
            );
            $address = implode(', ', $addressParts);
            $fullName = trim($user->first_name.' '.$user->last_name);

            match ($user->role) {
                UserRole::Petani->value => FarmerProfile::create([
                    'user_id' => $user->id,
                    'full_name' => $fullName,
                    'role_label' => 'Petani',
                    'location' => $address,
                    'village' => $addressParts[0],
                    'district' => $addressParts[1],
                    'city' => $addressParts[2],
                    'province' => $addressParts[3],
                    'contact' => $user->phone,
                ]),
                UserRole::Pengepul->value => CollectorProfile::create([
                    'user_id' => $user->id,
                    'business_name' => $fullName,
                    'address' => $address,
                    'village' => $addressParts[0],
                    'district' => $addressParts[1],
                    'city' => $addressParts[2],
                    'province' => $addressParts[3],
                    'contact' => $user->phone,
                ]),
                UserRole::Distributor->value => DistributorProfile::create([
                    'user_id' => $user->id,
                    'business_name' => $fullName,
                    'address' => $address,
                    'village' => $addressParts[0],
                    'district' => $addressParts[1],
                    'city' => $addressParts[2],
                    'province' => $addressParts[3],
                    'contact' => $user->phone,
                ]),
                UserRole::Umkm->value => UmkmProfile::create([
                    'user_id' => $user->id,
                    'name' => $fullName,
                    'owner_name' => $fullName,
                    'address' => $address,
                    'village' => $addressParts[0],
                    'district' => $addressParts[1],
                    'city' => $addressParts[2],
                    'province' => $addressParts[3],
                    'contact' => $user->phone,
                ]),
                UserRole::Konsumen->value => ConsumerProfile::create([
                    'user_id' => $user->id,
                    'display_name' => $fullName,
                    'address' => $address,
                    'village' => $addressParts[0],
                    'district' => $addressParts[1],
                    'city' => $addressParts[2],
                    'province' => $addressParts[3],
                    'phone' => $user->phone,
                ]),
            };

            return $user;
        });

        $token = $user->createToken('flutter')->plainTextToken;

        return $this->authResponse(
            $user,
            $token,
            'Registrasi berhasil',
            201
        );
    }

    /**
     * LOGIN
     */
    public function login(Request $request)
    {
        $request->validate([
            'identifier' => 'required|string',
            'password' => 'required|string',
            'role' => ['required', Rule::in(UserRole::values())],
        ]);

        $identifier = $request->string('identifier')->trim()->toString();
        $normalizedIdentifier = Str::lower($identifier);

        $user = User::whereRaw('LOWER(email) = ?', [$normalizedIdentifier])
            ->orWhere('username', $identifier)
            ->orWhere('phone', $identifier)
            ->first();

        if (! $user) {
            return ApiResponse::error('Akun tidak ditemukan', 404);
        }

        if (! $user->is_active) {
            return ApiResponse::error('Akun dinonaktifkan', 403);
        }

        if (! Hash::check($request->string('password')->toString(), $user->password)) {
            return ApiResponse::error('Password salah', 401);
        }

        if ($user->role !== $request->string('role')->toString()) {
            return ApiResponse::error('Role tidak sesuai', 403);
        }

        $user->forceFill([
            'last_login_at' => now(),
        ])->save();

        $token = $user->createToken('flutter')->plainTextToken;

        return $this->authResponse($user, $token, 'Login berhasil');
    }

    /**
     * USER LOGIN
     */
    public function me(Request $request)
    {
        return ApiResponse::success([
            'user' => new UserResource($request->user()),
            'dashboard' => $this->dashboardForRole($request->user()->role),
        ], 'Session aktif');
    }

    /**
     * LOGOUT
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return ApiResponse::success(null, 'Logout berhasil');
    }

    private function authResponse(User $user, string $token, string $message, int $status = 200)
    {
        return ApiResponse::success([
            'user' => new UserResource($user),
            'token' => $token,
            'dashboard' => $this->dashboardForRole($user->role),
        ], $message, $status);
    }

    private function dashboardForRole(string $role): string
    {
        return match ($role) {
            UserRole::Petani->value,
            UserRole::Pengepul->value,
            UserRole::Distributor->value,
            UserRole::Umkm->value,
            UserRole::Konsumen->value => $role,
            default => $role,
        };
    }

}
