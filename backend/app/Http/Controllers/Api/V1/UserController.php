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

        // The password is filled as-is because it is in the model's $fillable,
        // and the 'hashed' cast hashes it on the way in. Hash::make() here
        // would only add a second way to reach the same result.
        $user = User::query()->create([
            ...$validated,
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

        // Deactivating your own account locks the last admin out of the
        // system with no way back in through the UI.
        if (
            array_key_exists('is_active', $validated)
            && $validated['is_active'] === false
            && $user->is($request->user())
        ) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Anda tidak dapat menonaktifkan akun Anda sendiri.',
                ['is_active' => ['Akun sendiri tidak dapat dinonaktifkan.']],
                [],
                422,
            );
        }

        $user->fill($validated)->save();

        return ApiResponse::success(new UserResource($user->refresh()));
    }
}
