<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    /**
     * Successful response envelope: { data, meta: { request_id } }.
     */
    public static function success(mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => [
                'request_id' => RequestId::current(),
            ],
        ], $status);
    }

    /**
     * Error response envelope: { error: { code, message, fields, details }, meta: { request_id } }.
     *
     * The status defaults to 422 because most explicit errors carry field-level
     * validation failures. Pass a status when the code implies a different one.
     */
    public static function error(
        string $code,
        string $message,
        array $fields = [],
        array $details = [],
        int $status = 422,
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'fields' => $fields,
                'details' => $details,
            ],
            'meta' => [
                'request_id' => RequestId::current(),
            ],
        ], $status);
    }
}
