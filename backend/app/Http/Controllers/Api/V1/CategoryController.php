<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Category::class);

        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));
        $categories = Category::query()->orderBy('name')->paginate($perPage);

        return response()->json([
            'data' => CategoryResource::collection($categories->items()),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'current_page' => $categories->currentPage(),
                'per_page' => $categories->perPage(),
                'last_page' => $categories->lastPage(),
                'total' => $categories->total(),
            ],
        ]);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $this->authorize('create', Category::class);

        $validated = $request->validated();
        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['name']);

        $category = Category::query()->create($validated);

        return ApiResponse::success(new CategoryResource($category), 201);
    }

    public function show(Category $category): JsonResponse
    {
        return ApiResponse::success(new CategoryResource($category));
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $this->authorize('update', $category);

        $validated = $request->validated();

        if (isset($validated['name']) && ! isset($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $category->fill($validated)->save();

        return ApiResponse::success(new CategoryResource($category->refresh()));
    }

    public function destroy(Category $category): JsonResponse
    {
        $this->authorize('delete', $category);

        // Kategori yang sudah dipakai produk tidak boleh dihapus keras.
        if ($category->products()->exists()) {
            return ApiResponse::error(
                'CONFLICT',
                'Kategori ini sudah dipakai produk dan tidak dapat dihapus. Nonaktifkan saja.',
                [],
                [],
                409,
            );
        }

        $category->delete();

        return ApiResponse::success(null);
    }
}
