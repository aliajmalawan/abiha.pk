<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Turns every throwable into a user-safe response, like Laravel's exception handler:
 *  - RedirectException            -> 303 redirect
 *  - ValidationException          -> back with "errors" + "old" input (HTML) or 422 JSON {message, errors: {field: [msg]}}
 *  - HttpException(403/404/419/429) -> app/Views/errors/<code>.php inside the store layout (noindex)
 *  - HttpException(503)           -> standalone errors/503 page
 *  - anything else                -> logged with a reference, standalone errors/500 page (no layout, no database needed)
 */
final class ErrorHandler
{
    public static function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', Config::get('debug') && PHP_SAPI === 'cli' ? '1' : '0');
        ini_set('log_errors', '1');
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }

    public static function toResponse(\Throwable $e, Request $request): Response
    {
        if ($e instanceof RedirectException) {
            return Response::redirect($e->to);
        }
        if ($e instanceof ValidationException) {
            if ($request->wantsJson() || ($request->ajax() && $request->method !== 'GET')) {
                return Response::json([
                    'message' => $e->getMessage(),
                    'errors' => array_map(static fn ($m) => [$m], $e->errors),
                ], 422);
            }
            Session::flash('errors', $e->errors);
            Session::flash('old', self::safeOld($request));
            return Response::redirect($e->redirectTo ?? self::back($request));
        }

        $status = $e instanceof HttpException ? $e->status : 500;
        $ref = null;
        if ($status >= 500 && !($e instanceof HttpException && $status === 503)) {
            $ref = substr(bin2hex(random_bytes(6)), 0, 10);
            Logger::error('Unhandled exception', [
                'ref' => $ref,
                'type' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'path' => $request->path,
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
            ]);
        }
        $message = $e instanceof HttpException ? $e->getMessage() : 'Server Error';

        if ($request->wantsJson()) {
            $r = Response::json(['message' => $message], $status);
        } else {
            $r = Response::html(self::page($status, $e, $ref), $status);
        }
        if ($e instanceof HttpException) {
            foreach ($e->headers as $k => $v) {
                $r->header($k, $v);
            }
        }
        return $r;
    }

    /** Renders the error page for a status. Never throws. */
    public static function page(int $status, ?\Throwable $e = null, ?string $ref = null): string
    {
        $debug = Config::get('debug') && $e !== null && !($e instanceof HttpException)
            ? $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() : null;
        try {
            if (in_array($status, [403, 404, 419, 429], true)) {
                return (new \App\Controllers\ErrorPageController())->render($status);
            }
            $view = $status === 503 ? 'errors/503' : ($status >= 500 ? 'errors/500' : 'errors/minimal');
            return View::partial($view, ['status' => $status, 'debug' => $debug, 'ref' => $ref, 'title' => HttpException::defaultMessage($status)]);
        } catch (\Throwable $inner) {
            Logger::error('Error page failed', ['message' => $inner->getMessage(), 'file' => $inner->getFile() . ':' . $inner->getLine()]);
            try {
                return View::partial('errors/500', ['status' => 500, 'debug' => $debug, 'ref' => $ref]);
            } catch (\Throwable) {
                return '<!doctype html><meta charset="utf-8"><title>Something went wrong</title><p style="font-family:sans-serif;padding:2rem">Something went wrong on our side. Please try again in a few minutes.</p>';
            }
        }
    }

    private static function back(Request $request): string
    {
        $ref = (string) $request->header('Referer');
        $base = (string) Config::get('url');
        if ($ref !== '' && str_starts_with($ref, $base)) {
            return $ref;
        }
        return $request->path;
    }

    /**
     * Never re-populate secrets into forms. Outside the staff area only plain text values are kept (a visitor can submit
     * name[]=x; no public form has array fields), like the old ScalarOldInput middleware.
     */
    private static function safeOld(Request $request): array
    {
        $post = $request->post;
        unset($post['_token'], $post['_method'], $post['password'], $post['password_confirmation'], $post['current_password']);
        if (!Middleware::isAdminPath($request->path)) {
            $post = array_filter($post, static fn ($v) => is_scalar($v) || $v === null);
        }
        return $post;
    }
}
