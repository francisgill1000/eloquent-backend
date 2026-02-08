<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Database\QueryException;
use Throwable;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;

class Handler extends ExceptionHandler
{
    // ...

    public function render($request, Throwable $exception)
    {
        if ($request->expectsJson()) {

            if ($exception instanceof QueryException) {
                Log::error('DB Error', [
                    'error' => $exception->getMessage(),
                    'trace' => $exception->getTraceAsString(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Database error occurred',
                ], 500);
            }

            Log::error('App Error', [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong',
            ], 500);
        }

        // For non-JSON requests, fallback to default
        return parent::render($request, $exception);
    }
}
