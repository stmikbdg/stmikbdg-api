<?php

namespace App\Exceptions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;
// JWT Exception
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;

class ErrorHandler
{
    public static function handle(Throwable $e, bool $isToken = false): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return self::handleValidationException($e);
        } elseif ($e instanceof QueryException) {
            return self::handleQueryException($e);
        } elseif ($e instanceof ModelNotFoundException) {
            return self::handleModelNotFoundException($e);
        } elseif ($e instanceof HttpException) {
            return self::handleHttpException($e);
        } elseif ($e instanceof TokenInvalidException) {
            return self::handleTokenInvalidException($e);
        } elseif ($e instanceof TokenExpiredException) {
            return self::handleTokenExpiredException($e);
        } else {
            return self::handleGenericException($e, $isToken);
        }
    }

    private static function handleTokenInvalidException(TokenInvalidException $e): JsonResponse
    {
        return response()->json([
            'status' => 'Invalid Token',
            'message' => $e->getMessage(),
        ], 400);
    }

    private static function handleTokenExpiredException(TokenExpiredException $e): JsonResponse
    {
        return response()->json([
            'status' => 'Expired Token',
            'message' => $e->getMessage(),
        ], 401);
    }

    private static function handleValidationException(ValidationException $e): JsonResponse
    {
        return response()->json([
            'status' => 'Validation Failed',
            'message' => $e->getMessage(),
            'errors' => $e->validator->errors(),
        ], 422);
    }

    private static function handleQueryException(QueryException $e): JsonResponse
    {
        Log::error('Database query error', [
            'message' => $e->getMessage(),
            'sql' => $e->getSql(),
            'bindings' => $e->getBindings(),
        ]);

        return response()->json([
            'status' => 'Database Error',
            'message' => 'Terjadi masalah pada database. Pastikan migrasi sudah dijalankan atau hubungi administrator.',
        ], 500);
    }

    private static function handleModelNotFoundException(ModelNotFoundException $e): JsonResponse
    {
        return response()->json([
            'status' => 'Not Found',
            'message' => 'Data tidak ditemukan atau sudah dihapus.',
        ], 404);
    }

    private static function handleHttpException(HttpException $e): JsonResponse
    {
        return response()->json([
            'status' => 'HTTP Error',
            'message' => $e->getMessage(),
        ], $e->getStatusCode());
    }

    private static function handleGenericException(Throwable $e, $isToken): JsonResponse
    {
        if ($isToken) {
            return response()->json([
                'status' => 'Fail',
                'message' => 'Access denied. Token not found',
            ], 401);
        }

        Log::error('Unhandled application error', [
            'exception' => $e,
        ]);

        return response()->json([
            'status' => 'Internal Server Error',
            'message' => 'Terjadi kesalahan pada server. Silakan coba kembali atau hubungi administrator.',
        ], 500);
    }
}
