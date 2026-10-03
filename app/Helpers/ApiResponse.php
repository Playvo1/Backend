<?php

namespace App\Helpers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ApiResponse
{
    public static function send(
        bool $success,
        int $statusCode,
        string $message,
        mixed $data = null,
        mixed $errors = null
    ) {
        return response()->json([
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
        ], $statusCode);
    }

    /**
     * The contract's list payload (Section 7.1): {items, total, page, per_page}.
     */
    public static function paginated(LengthAwarePaginator $paginator, array $items): array
    {
        return [
            'items' => $items,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
        ];
    }
}
