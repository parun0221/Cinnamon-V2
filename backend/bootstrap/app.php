<?php

use App\Http\Middleware\RequestIdMiddleware;
use App\Support\RequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->append(RequestIdMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->expectsJson() || str_starts_with($request->path(), 'api/');
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->expectsJson() && ! str_starts_with($request->path(), 'api/')) {
                return null;
            }

            // Laravel rewrites several exceptions into Symfony HttpExceptions
            // before render callbacks run (TokenMismatchException → 419,
            // OriginMismatchException → 403, AuthorizationException → 403), so
            // the HTTP status is what identifies them here.
            $httpStatus = $e instanceof HttpException ? $e->getStatusCode() : null;

            $code = match (true) {
                $e instanceof ValidationException => 'VALIDATION_ERROR',
                $e instanceof AuthenticationException => 'UNAUTHENTICATED',
                $e instanceof AuthorizationException => 'FORBIDDEN',
                $e instanceof AccessDeniedHttpException => 'FORBIDDEN',
                $e instanceof ModelNotFoundException => 'NOT_FOUND',
                $e instanceof NotFoundHttpException => 'NOT_FOUND',
                $e instanceof MethodNotAllowedHttpException => 'BAD_REQUEST',
                $e instanceof ThrottleRequestsException => 'RATE_LIMITED',
                $httpStatus === 419 => 'UNAUTHENTICATED',
                $httpStatus === 403 => 'FORBIDDEN',
                $httpStatus === 404 => 'NOT_FOUND',
                $httpStatus === 405 => 'BAD_REQUEST',
                $httpStatus === 429 => 'RATE_LIMITED',
                $httpStatus !== null && $httpStatus >= 400 && $httpStatus < 500 => 'BAD_REQUEST',
                default => 'SERVER_ERROR',
            };

            $status = match ($code) {
                'VALIDATION_ERROR' => 422,
                'UNAUTHENTICATED' => $httpStatus === 419 ? 419 : 401,
                'FORBIDDEN' => 403,
                'NOT_FOUND' => 404,
                'BAD_REQUEST' => 400,
                'RATE_LIMITED' => 429,
                default => 500,
            };

            $message = match ($code) {
                'VALIDATION_ERROR' => 'Validasi gagal.',
                'UNAUTHENTICATED' => $httpStatus === 419
                    ? 'Sesi Anda telah berakhir. Silakan muat ulang halaman.'
                    : 'Sesi tidak valid.',
                'FORBIDDEN' => 'Akses tidak diizinkan.',
                'NOT_FOUND' => 'Resource tidak ditemukan.',
                'BAD_REQUEST' => 'Permintaan tidak dapat diproses.',
                'RATE_LIMITED' => 'Terlalu banyak permintaan.',
                default => 'Terjadi kesalahan server.',
            };

            $fields = $e instanceof ValidationException
                ? $e->errors()
                : [];

            return response()->json([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'fields' => $fields,
                    'details' => [],
                ],
                'meta' => [
                    'request_id' => RequestId::current(),
                ],
            ], $status);
        });
    })->create();
