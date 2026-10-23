<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Requests\Product\UploadProductImageRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Queries\ProductQuery;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));

        $products = ProductQuery::build()
            ->filter($request->only(['category_id', 'search', 'is_active', 'stock_status']))
            ->sort($request->input('sort'))
            ->paginate($perPage);

        return response()->json([
            'data' => ProductResource::collection($products->items()),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'last_page' => $products->lastPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        $product = Product::query()->create($request->validated());
        $product->load('category');

        return ApiResponse::success(new ProductResource($product), 201);
    }

    public function show(Product $product): JsonResponse
    {
        $product->load('category');

        return ApiResponse::success(new ProductResource($product));
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $product->fill($request->validated())->save();
        $product->load('category');

        return ApiResponse::success(new ProductResource($product->refresh()));
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        // Produk yang sudah dipakai receipt item tidak boleh dihapus keras.
        // Relasi incomingReceiptItems akan dibuat di Phase 2.2.
        if (method_exists($product, 'incomingReceiptItems') && $product->incomingReceiptItems()->exists()) {
            return ApiResponse::error(
                'CONFLICT',
                'Produk ini sudah dipakai penerimaan dan tidak dapat dihapus. Nonaktifkan saja.',
                [],
                [],
                409,
            );
        }

        $product->delete();

        return ApiResponse::success(null);
    }

    public function uploadImage(UploadProductImageRequest $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $file = $request->file('image');
        $path = $file->store('products', 'public');

        // Hapus gambar lama jika ada
        if ($product->image_path) {
            Storage::disk('public')->delete($product->image_path);
        }

        $product->update(['image_path' => $path]);

        return ApiResponse::success(new ProductResource($product->refresh()));
    }
}
