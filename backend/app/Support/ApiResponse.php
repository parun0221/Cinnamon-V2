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
     */
    public static function error(
        string $code,
        string $message,
        array $fields = [],
        array $details = [],
        int $status = 400,
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
