# Browser UI checks

PHPUnit proves the markup and rules (`tests/Feature/UiPolishTest.php`). These checks prove the **behaviour** in a real
browser, which PHPUnit cannot: focus moving into dialogs and looping with Tab, Escape closing and restoring focus,
scroll lock, the cart drawer, scroll reveal, inline validation, submit spinners and `prefers-reduced-motion`.

```
php artisan migrate:fresh --seed        # the checks use the sample catalogue (Sample Product 001)
php artisan serve --port=8100
node tests/browser/run.mjs              # or: node tests/browser/run.mjs http://127.0.0.1:8100
```

It starts a headless Edge/Chrome (set `BROWSER_PATH` to use another), runs every check twice (normal motion, then
reduced motion emulated), prints failures, and removes its temporary page from `public/` afterwards. Exit code 1 means
at least one check failed.

Notes
- Real time is required: CSS transitions do not advance under headless "virtual time", so the harness uses the
  DevTools protocol instead of `--virtual-time-budget`.
- The dev server is single-threaded, so the harness polls for results instead of using fixed waits.

## End-to-end QA across screen sizes: `qa.mjs`

Drives the real UI (real mouse and keyboard events) at four sizes: desktop 1440×900, tablet 820×1180, phone 390×844 and a
small phone 360×640. For every size it audits each page (horizontal overflow, broken images, console errors, failed
requests, exactly one `<h1>`, alt text) and runs the journeys: navigation, search with suggestions, price filter and sort,
wishlist, variant selection, add to bag and the bag drawer, coupon, a full cash-on-delivery checkout, order tracking,
customer sign-in/sign-out, the whole admin area, an admin order status change, a product save and a settings save.
Tap targets under 24 px and text under 11 px are reported as advisory warnings, not failures.

**It places real orders and edits data. Point it at a scratch database, never at the live site or your development data.**

```
# 1. a scratch database with sample data, an admin and a customer
DB_CONNECTION=sqlite DB_DATABASE=/path/to/qa.sqlite CACHE_PREFIX=qa_ php artisan migrate --seed
DB_CONNECTION=sqlite DB_DATABASE=/path/to/qa.sqlite php artisan abiha:create-admin qa-admin@example.test --password=QaAdminPass2026
#    (create a customer user and a no-minimum coupon the same way, e.g. in tinker)

# 2. serve it with APP_URL set to the address you will browse
DB_CONNECTION=sqlite DB_DATABASE=/path/to/qa.sqlite CACHE_PREFIX=qa_ APP_URL=http://127.0.0.1:8102 php artisan serve --port=8102

# 3. qa.json: {"customer":{"email":"…","password":"…"},"admin":{"email":"…","password":"…"},
#              "product":"slug-of-an-in-stock-simple-product","variantProduct":"slug","category":"slug","page":"slug","coupon":"CODE"}
node tests/browser/qa.mjs http://127.0.0.1:8102 --config qa.json [--shots ./shots] [--only mobile,desktop] [--grep "checkout"]
```

Each run buys products, so restock the scratch database between runs or the product page will correctly say **Sold out**.

## Core Web Vitals in the lab: `vitals.mjs`

`node tests/browser/vitals.mjs <base-url> / /shop /products/<slug> [--desktop] [--no-throttle]` measures FCP, LCP (and which
element), CLS, long-task time, bytes on the wire and image formats, on a throttled mobile profile by default. It is lab data
from your machine; real-user numbers come from Search Console once the site is live.
