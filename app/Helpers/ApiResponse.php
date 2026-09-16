<?php

namespace App\Helpers;

class ApiResponse
{
    public static function send(
        bool $success,
        int $statusCode,
        string $message,
        mixed $data = null
    ) {
        return response()->json([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }
}
