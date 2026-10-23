<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));
        $customers = Customer::query()->orderBy('name')->paginate($perPage);

        return response()->json([
            'data' => CustomerResource::collection($customers->items()),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'current_page' => $customers->currentPage(),
                'per_page' => $customers->perPage(),
                'last_page' => $customers->lastPage(),
                'total' => $customers->total(),
            ],
        ]);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $this->authorize('create', Customer::class);

        $customer = Customer::query()->create($request->validated());

        return ApiResponse::success(new CustomerResource($customer), 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        return ApiResponse::success(new CustomerResource($customer));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        $customer->fill($request->validated())->save();

        return ApiResponse::success(new CustomerResource($customer->refresh()));
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->authorize('delete', $customer);

        // Customer yang sudah dipakai sale tidak boleh dihapus keras.
        // Relasi sales akan dibuat di Phase 3.1.
        if (method_exists($customer, 'sales') && $customer->sales()->exists()) {
            return ApiResponse::error(
                'CONFLICT',
                'Customer ini sudah dipakai penjualan dan tidak dapat dihapus. Nonaktifkan saja.',
                [],
                [],
                409,
            );
        }

        $customer->delete();

        return ApiResponse::success(null);
    }
}
