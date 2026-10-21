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

            $code = match (true) {
                $e instanceof ValidationException => 'VALIDATION_ERROR',
                $e instanceof AuthenticationException => 'UNAUTHENTICATED',
                $e instanceof AuthorizationException => 'FORBIDDEN',
                $e instanceof AccessDeniedHttpException => 'FORBIDDEN',
                $e instanceof ModelNotFoundException => 'NOT_FOUND',
                $e instanceof NotFoundHttpException => 'NOT_FOUND',
                $e instanceof MethodNotAllowedHttpException => 'BAD_REQUEST',
                $e instanceof ThrottleRequestsException => 'RATE_LIMITED',
                // Laravel rewrites TokenMismatchException to HttpException(419)
                // before render callbacks run, so the status is what survives.
                $e instanceof HttpException
                    && $e->getStatusCode() === 419 => 'UNAUTHENTICATED',
                default => 'SERVER_ERROR',
            };

            $status = match ($code) {
                'VALIDATION_ERROR' => 422,
                'UNAUTHENTICATED' => $e instanceof HttpException
                    && $e->getStatusCode() === 419 ? 419 : 401,
                'FORBIDDEN' => 403,
                'NOT_FOUND' => 404,
                'BAD_REQUEST' => 400,
                'RATE_LIMITED' => 429,
                default => 500,
            };

            $message = match ($code) {
                'VALIDATION_ERROR' => 'Validasi gagal.',
                'UNAUTHENTICATED' => $e instanceof HttpException
                    && $e->getStatusCode() === 419
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
