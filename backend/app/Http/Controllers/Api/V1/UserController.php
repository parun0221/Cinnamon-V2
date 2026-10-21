<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\RequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));

        $users = User::query()->orderBy('name')->paginate($perPage);

        return response()->json([
            'data' => UserResource::collection($users->items()),
            'meta' => [
                'request_id' => RequestId::current(),
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'last_page' => $users->lastPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $validated = $request->validated();

        // The password is hashed here rather than left to the model's
        // 'hashed' cast. The cast only hashes a value that is not already a
        // hash, so a password which happens to look like a bcrypt string
        // would be stored verbatim — letting anyone who knows a well-known
        // hash plant a password of their choosing, and making a high-cost
        // hash throw instead of validate.
        $user = User::query()->create([
            ...$validated,
            'password' => Hash::make($validated['password']),
            'is_active' => true,
        ]);

        return ApiResponse::success(new UserResource($user), 201);
    }

    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return ApiResponse::success(new UserResource($user));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $validated = $request->validated();

        // Demoting or deactivating an account takes away the access it
        // currently holds. Doing that to the last active admin locks every
        // admin out of the system with no way back in through the UI, so the
        // last one standing cannot be removed.
        if ($this->removesTheLastAdmin($validated, $user)) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Admin terakhir tidak dapat dinonaktifkan atau diturunkan perannya.',
                [
                    'is_active' => ['Admin terakhir harus tetap aktif dan berperan Admin.'],
                    'role' => ['Admin terakhir harus tetap aktif dan berperan Admin.'],
                ],
                [],
                422,
            );
        }

        $user->fill($validated)->save();

        return ApiResponse::success(new UserResource($user->refresh()));
    }

    /**
     * Whether this change would leave the system without an active admin.
     *
     * @param  array<string, mixed>  $validated
     */
    private function removesTheLastAdmin(array $validated, User $user): bool
    {
        $staysAdmin = ($validated['role'] ?? $user->role) === 'admin';
        $staysActive = $validated['is_active'] ?? $user->is_active;

        if ($staysAdmin && $staysActive) {
            return false;
        }

        return ! User::query()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->whereKeyNot($user->getKey())
            ->exists();
    }
}
