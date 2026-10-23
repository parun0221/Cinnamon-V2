<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Supplier\StoreSupplierRequest;
use App\Http\Requests\Supplier\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Supplier::class);

        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));
        $suppliers = Supplier::query()->orderBy('name')->paginate($perPage);

        return response()->json([
            'data' => SupplierResource::collection($suppliers->items()),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'current_page' => $suppliers->currentPage(),
                'per_page' => $suppliers->perPage(),
                'last_page' => $suppliers->lastPage(),
                'total' => $suppliers->total(),
            ],
        ]);
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $this->authorize('create', Supplier::class);

        $supplier = Supplier::query()->create($request->validated());

        return ApiResponse::success(new SupplierResource($supplier), 201);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        return ApiResponse::success(new SupplierResource($supplier));
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $this->authorize('update', $supplier);

        $supplier->fill($request->validated())->save();

        return ApiResponse::success(new SupplierResource($supplier->refresh()));
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $this->authorize('delete', $supplier);

        // Supplier yang sudah dipakai receipt tidak boleh dihapus keras.
        // Relasi incomingReceipts akan dibuat di Phase 2.2.
        if (method_exists($supplier, 'incomingReceipts') && $supplier->incomingReceipts()->exists()) {
            return ApiResponse::error(
                'CONFLICT',
                'Supplier ini sudah dipakai penerimaan dan tidak dapat dihapus. Nonaktifkan saja.',
                [],
                [],
                409,
            );
        }

        $supplier->delete();

        return ApiResponse::success(null);
    }
}
