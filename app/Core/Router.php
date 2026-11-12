<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Small router with per-route middleware.
 *
 * Middleware strings (see Core\Middleware):
 *   auth            – signed-in, active account (guests go to /login, or /admin/login under /admin)
 *   guest           – only when signed out (signed-in users go to /admin or /account)
 *   staff           – signed-in user whose role is a staff role (roles.is_staff = 1), with the admin idle timeout
 *   perm:<slug>     – staff whose role holds the permission slug (the Super Admin role "admin" holds all)
 *   nocsrf          – skip the CSRF check (signed payment webhooks only)
 *
 * Route parameters: "/category/{slug}" or with a pattern "/customers/{id:\d+}".
 * The name table is static so the route() helper can build URLs from views.
 */
final class Router
{
    /** @var list<array{method:string,regex:string,keys:list<string>,handler:array|callable,mw:list<string>}> */
    private array $routes = [];
    private array $groupMw = [];
    private string $groupPrefix = '';

    /** @var array<string,string> route name => path pattern */
    private static array $names = [];

    /** @var array<string,true> names registered on THIS router instance */
    private array $own = [];

    public function __construct()
    {
        // A new router (one per request; several per test run) starts with a clean name table.
        self::$names = [];
    }

    /** True when a route with this name is registered on this router (used by fallback routes). */
    public function has(string $name): bool
    {
        return isset($this->own[$name]);
    }

    public function get(string $path, array|callable $handler, array $mw = [], ?string $name = null): void
    {
        $this->add('GET', $path, $handler, $mw, $name);
    }

    public function post(string $path, array|callable $handler, array $mw = [], ?string $name = null): void
    {
        $this->add('POST', $path, $handler, $mw, $name);
    }

    public function put(string $path, array|callable $handler, array $mw = [], ?string $name = null): void
    {
        $this->add('PUT', $path, $handler, $mw, $name);
    }

    public function patch(string $path, array|callable $handler, array $mw = [], ?string $name = null): void
    {
        $this->add('PATCH', $path, $handler, $mw, $name);
    }

    public function delete(string $path, array|callable $handler, array $mw = [], ?string $name = null): void
    {
        $this->add('DELETE', $path, $handler, $mw, $name);
    }

    /** Path pattern for a route name, e.g. "/category/{slug}". Used by the route() helper. */
    public static function pattern(string $name): ?string
    {
        return self::$names[$name] ?? null;
    }

    /** @return array<string,string> every route name => path pattern */
    public static function names(): array
    {
        return self::$names;
    }

    public function group(string $prefix, array $mw, callable $define): void
    {
        [$p, $m] = [$this->groupPrefix, $this->groupMw];
        $this->groupPrefix = $p . $prefix;
        $this->groupMw = array_merge($m, $mw);
        $define($this);
        [$this->groupPrefix, $this->groupMw] = [$p, $m];
    }

    private function add(string $method, string $path, array|callable $handler, array $mw, ?string $name = null): void
    {
        $full = '/' . trim($this->groupPrefix . $path, '/');
        if ($name !== null) {
            $this->own[$name] = true;
            self::$names[$name] =preg_replace('#\{([a-z_]+)(?::(?:[^{}]|\{\d+(?:,\d*)?\})+)?\}#', '{$1}', $full);
        }
        $keys = [];
        // Parameter patterns may contain regex quantifiers such as {24} or {2,5}.
        $regex = preg_replace_callback('#\{([a-z_]+)(?::((?:[^{}]|\{\d+(?:,\d*)?\})+))?\}#', static function ($m) use (&$keys) {
            $keys[] = $m[1];
            return '(' . ($m[2] ?? '[^/]+') . ')';
        }, $full);
        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'keys' => $keys,
            'handler' => $handler,
            'mw' => array_merge($this->groupMw, $mw),
        ];
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            if ($route['method'] !== $request->method && !($route['method'] === 'GET' && $request->method === 'HEAD')) {
                $allowed[] = $route['method'];
                continue;
            }
            array_shift($m);
            $request->params = array_combine($route['keys'], array_map('strval', $m)) ?: [];

            Middleware::run($route['mw'], $request);

            $handler = $route['handler'];
            if (is_array($handler)) {
                [$class, $action] = $handler;
                $result = (new $class())->$action($request);
            } else {
                $result = $handler($request);
            }
            return $result instanceof Response ? $result : Response::html((string) $result);
        }
        if ($allowed) {
            throw new HttpException(405, '', ['Allow' => implode(', ', array_unique($allowed))]);
        }
        $lower = strtolower($request->path);
        if ($request->method === 'GET' && $lower !== $request->path && preg_match('#^/[a-z0-9/_-]*$#', $lower)) {
            throw new RedirectException($lower);
        }
        throw new HttpException(404);
    }
}
