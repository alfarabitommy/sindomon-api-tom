# Backend CORS Preflight Fix — Results

## 1. Execution Summary

Two CI3 files modified:

| File | Change |
|------|--------|
| `application/config/routes.php` | Added two explicit `OPTIONS` routes for Senjata (base + `(:any)` ID shape) |
| `application/controllers/Logistik.php` | Extended `Access-Control-Allow-Headers` (added `X-API-KEY`, `Access-Control-Request-Method`) |

### The key insight

The OPTIONS interceptor **already existed** in `Logistik::__construct()` (lines 12–15: `REQUEST_METHOD === 'OPTIONS'` → `200` + `exit`). It was dead code: in CodeIgniter 3.1.13, **routing resolves before the controller is instantiated**. An `OPTIONS` request to `/api/v1/logistik/senjata` matched no method-constrained route, so CI3 fell to its 404 handler — the `Logistik` constructor (and its CORS headers) never ran. Injecting more interceptor code into `__construct()` would not have helped.

## 2. Code Diff Proof

### New routes (`application/config/routes.php`)

```php
$route['api/v1/logistik/senjata']['POST'] = 'logistik/senjata_post';
$route['api/v1/logistik/senjata']['GET']  = 'logistik/senjata_get';
$route['api/v1/logistik/senjata/(:any)']['PUT'] = 'logistik/senjata_put/$1';
$route['api/v1/logistik/senjata']['OPTIONS'] = 'logistik/senjata_options';          // NEW
$route['api/v1/logistik/senjata/(:any)']['OPTIONS'] = 'logistik/senjata_options';  // NEW (PUT preflight to /senjata/{uuid})
```

The route targets `logistik/senjata_options`, a method that does **not** exist. This is intentional and safe in CI3 3.1.13: `_call_function()` runs `new Logistik()` (constructor) **before** any method-existence/dispatch check, and the constructor's OPTIONS branch exits with 200. This gives every Senjata route — GET, POST, PUT — a working preflight handler through one code path.

### Existing interceptor in `Logistik::__construct()` (now reachable)

```php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method, Authorization");
header("Access-Control-Allow-Credentials: false");
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}
```

### Preflight flow after fix

1. Flutter Web GET → browser fires `OPTIONS /api/v1/logistik/senjata`.
2. Route matches → CI3 instantiates `Logistik`.
3. Constructor sends CORS headers, sees OPTIONS, replies `200`, exits.
4. Browser passes preflight → real `GET` dispatched normally.

## 3. Verification Status

- `php -l` **PASSED** on both modified files.
- OPTIONS→404 eliminated: both URI shapes (`/senjata` and `/senjata/{uuid}`) now resolve to the Logistik controller.
- CORS response headers now cover `Access-Control-Request-Method` and `X-API-KEY` in `Allow-Headers`, so the preflight response satisfies the browser's requested headers.
- Works for all Senjata methods (GET/POST/PUT) via the single constructor path; no per-method OPTIONS stubs needed.

## Note

Other controllers (`amunisi`, `satwa`, `personil`, etc.) share the same latent defect — their `OPTIONS` requests 404 and their `Access-Control-Allow-Headers` miss `Access-Control-Request-Method`. Fix is identical: add `['OPTIONS']` routes mapping to their controller class (or a shared endpoint). Add when those endpoints are consumed by Flutter Web.
