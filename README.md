# E-Commerce

A small e-commerce app: product CRUD, product search, and a "purchase" flow with a fake payment. Built for the LoanPro code challenge.

> **Status:** the backend (domain, services and HTTP API) and the frontend (product list, search, product form, cart and checkout) are done (see [Roadmap](#roadmap)).

## Stack

| Part | Tech |
|---|---|
| Backend | PHP 8.3 (php-fpm), Slim 4, PHP-DI, Monolog |
| Database | MySQL 8.4 |
| Frontend | React 18 + Vite, served by nginx |
| Tooling | PHPUnit, PHPStan, PHPCS, Docker Compose |

## Project layout

```
.
├── backend/                 Slim API
│   ├── app/                 Settings, DI wiring, routes, middleware
│   ├── src/
│   │   ├── Domain/          Entities, repository interfaces, domain exceptions
│   │   ├── Application/     Actions, handlers, middleware, settings
│   │   └── Infrastructure/  Persistence implementations (PDO / MySQL)
│   ├── db/
│   │   ├── init/            01-schema.sql, 02-seed.sql (run on first DB start)
│   │   └── seed/            build_seed.py: generates 02-seed.sql from the CSV
│   └── tests/
├── frontend/                React app (Vite) + nginx config + Dockerfile
│   └── src/                 api/ (fetch client), cart/ (context), components/,
│                            hooks/, lib/ (money), pages/, styles.css
├── docker-compose.yaml      db + api + web
├── DATA_CLEANING_LOG.md     Every change made to the source CSV, with reasons
└── LoanPro Code Challenge E-Commerce - ....csv   Source product data
```

## Running with Docker

```bash
docker compose up --build
```

The storefront is served at http://localhost:8080 (the product admin is at http://localhost:8080/admin/products; see [App pages](#app-pages)). nginx serves the frontend and forwards `/api/*` to the PHP-FPM container (the `/api` prefix is stripped before reaching Slim).

Configuration is read from environment variables, all optional:

| Variable | Default |
|---|---|
| `WEB_PORT` | `8080` |
| `APP_ENV` | `production` |
| `DB_NAME` / `DB_USER` / `DB_PASSWORD` | `ecommerce` |
| `DB_ROOT_PASSWORD` | `root` |

**Note:** the SQL in `backend/db/init/` only runs when the MySQL volume is created. After changing the schema or seed, reset it:

```bash
docker compose down -v && docker compose up --build
```

## Running the backend locally

```bash
cd backend
composer install
composer start      # php -S localhost:8080 -t public
composer test       # phpunit
```

From the repo root, `make test` runs the suite in a container. The tests that need real MySQL parallelism (`backend/tests/Concurrency`, `ProductOptimisticLockMySqlTest`) are skipped unless `TEST_DB_HOST` is set. `make test-concurrency` starts a throwaway `mysql:8.4` container (`ecommerce-test-db`, tmpfs storage, also published on `127.0.0.1:3310`, separate from the dev stack) and runs them against its `ecommerce_test` database; `make test-db-down` removes it. The tests drop and recreate tables, so they refuse any database whose name does not end in `_test`. To use your own server: `TEST_DB_HOST=... TEST_DB_PORT=... TEST_DB_NAME=something_test TEST_DB_USER=... TEST_DB_PASSWORD=... vendor/bin/phpunit` (needs `pdo_mysql`).

## Frontend development

```bash
cd frontend
npm install
npm run dev     # Vite dev server on http://localhost:5173
npm test        # Vitest + Testing Library (jsdom)
npm run build   # production build into dist/
```

Source layout in `frontend/src/`: `api/` (fetch client and endpoint modules), `cart/` (client-only cart in a Context, persisted to localStorage), `components/` (layout, shared product browser, table, pagination, error and spinner), `hooks/` (`useProducts`), `lib/` (money helpers; prices are integer cents), `pages/` (routes) and `styles.css`. Search and filter state lives in the URL query string.

### App pages

| Path | Page |
|---|---|
| `/` | Redirects to `/products` |
| `/products` | Storefront: search, category filter, pagination, Add to cart (`?q=`, `?category=`, `?page=`) |
| `/cart` | Cart and checkout |
| `/orders/:id` | Order result (PAID or FAILED); works on reload |
| `/admin` | Redirects to `/admin/products` |
| `/admin/products` | Admin list: SKU, Edit, Delete, New product (same `?q=`, `?category=`, `?page=`) |
| `/admin/products/new` | Create product |
| `/admin/products/:id/edit` | Edit product |

The old `/products/new` and `/products/:id/edit` redirect to the admin paths. Anything else shows a not-found page. Under Docker these are served at `http://localhost:8080<path>` and deep links work on reload. There is no authentication, so the storefront/admin split is a UX separation only, not access control.

**Dev proxy.** The app calls the API at `/api`. In Docker, nginx serves that path; under `npm run dev` the Vite dev server proxies `/api` instead (see `frontend/vite.config.js`). The production build has no dev-proxy dependency. By default the proxy targets `http://localhost:8080` (the Docker stack) and forwards the path unchanged, since that nginx strips `/api` itself. Two variables adjust this:

| Variable | Default | Purpose |
|---|---|---|
| `VITE_API_PROXY_TARGET` | `http://localhost:8080` | Where `/api` is proxied |
| `VITE_API_PROXY_STRIP_PREFIX` | unset | Set to `1` to strip `/api` before forwarding, for targeting Slim directly |

For example, with the backend running locally via `composer start`:

```bash
VITE_API_PROXY_TARGET=http://localhost:8080 VITE_API_PROXY_STRIP_PREFIX=1 npm run dev
```

(If `composer start` and Docker would both use 8080, change one of the ports.)

## Data

`products` is seeded with **88 products** from the challenge CSV, which was downloaded on Friday, 2026-10-30. The CSV contained dirty data (currency symbols, `free` prices, negative stock, duplicate SKUs, empty rows; two SQL/XSS injection test rows are dropped), which is cleaned by `backend/db/seed/build_seed.py`. Every decision is recorded in [DATA_CLEANING_LOG.md](DATA_CLEANING_LOG.md).

To regenerate the seed:

```bash
python3 backend/db/seed/build_seed.py
```

The two SQL/XSS injection test rows in the CSV (XS-001, SQL-001) are dropped from the seed. User-entered products can still contain such text, so all queries must use bound parameters and all output must be escaped.

## Domain design

Anemic entities with one repository per entity and a service layer.

- **Product**: sku, name, description, category, price, stock, weight, active (soft delete)
- **Order** / **OrderItem**: an order with line items that snapshot product name, sku and unit price at purchase time
- **Repositories**: `ProductRepository` (CRUD + FULLTEXT search), `OrderRepository`
- **Services**: `ProductService`, `PurchaseService`, and a `PaymentGateway` interface with a fake implementation
- **Money** is handled as integer cents in the domain.

The full plan is in `.claude/plans/domain-model.md`.

## Design decisions

What was chosen, what was considered instead, and why.

| Area | Chosen | Considered instead | Why |
|---|---|---|---|
| Stock safety | An atomic conditional `UPDATE ... WHERE stock >= ?` plus `SELECT ... FOR UPDATE` row locks, all in one transaction | An application-level check only; reserving stock when an item is added to the cart | A check-then-write in PHP can oversell under concurrency. Reservations need expiry and cleanup that this scope does not justify. |
| Concurrent edits | A `products.version` column bumped on every write; `PUT` must send the version it loaded and gets 409 if stale | `updated_at` as the token; last write wins | `TIMESTAMP` has one-second precision, so an edit and a purchase in the same second would collide. Last write wins silently reverts stock sold in the meantime. |
| Deadlocks | Rows locked in ascending id order, plus a bounded retry (3 attempts, jittered backoff) that ends in a 503 with `Retry-After` | No retry; retrying any error | Sorting makes deadlocks rare, not impossible. Only transient lock errors are safe to retry, and a 503 tells the client to try again instead of reporting a 500. |
| Payment order | Decrement stock, then charge; a decline rolls the decrement back | Charge first, then take stock | A customer is never charged for goods that cannot be delivered. |
| Declined payment | `201` with a `FAILED` order | An error response | A decline is an expected outcome and is recorded as an order. The cart is kept and no stock is taken. |
| Money | Integer cents everywhere (`priceCents`, `totalCents`); the form parses decimal strings without floats | Decimals or floats | No rounding errors. |
| Delete | Soft delete (`active = 0`); deleted products return 404 | Hard delete | Order items reference products. |
| Search | MySQL `FULLTEXT` on name, description and category (whole words only) | `LIKE '%q%'` substring search; a separate search service | Indexed and good enough for the catalog size. The cost is that `shoe` does not match `shoes`. |
| Pagination | `page` and `size` with `meta.total` | Cursor pagination | The UI needs "page X of Y" and the data set is small. |
| Seed data | Clean in `backend/db/seed/build_seed.py` and log every change in `DATA_CLEANING_LOG.md` | Hand-editing the CSV | The source stays intact and the cleaning can be reproduced. Rows with injection-style names are dropped. |
| Frontend state | Plain `fetch`, React Context with `useReducer` for the cart (kept in `localStorage`), search state in the URL | A data-fetching library; a state library | Seven endpoints and one piece of shared state do not need either. URL state makes pages shareable and survives reload. |
| Storefront vs admin | Separate routes: `/products` for shoppers, `/admin/products` for editing | One list with edit, delete and add to cart together | Different jobs, and Delete next to Add to cart invites misclicks. |

### Known limitations

- The payment charge runs inside the database transaction, so it holds row locks while it runs. That is harmless with the fake gateway. A real gateway would need stock reserved first, the charge made outside the transaction, and an idempotency key.
- There are no idempotency keys, so a client that retries after a timeout can create a second order.
- There is no authentication, so the storefront/admin split is a UX separation only, not access control.
- Search matches whole words only.
- Out of scope: user accounts, a server-side cart, a real payment form, an order history list, product images, i18n.

## API

All routes are served under `/api` by nginx (the prefix is stripped before Slim, so the routes below are registered without it). Requests and responses are JSON. Money is always integer cents (`priceCents`, `totalCents`, `unitPriceCents`). Dates are ISO 8601.

| Method | Path | Success | Notes |
|---|---|---|---|
| GET | `/products` | 200 | `page` (default 1, max 1000000), `size` (default 20, max 100); response has `meta` |
| GET | `/products/search` | 200 | `q` (max 255) and `category` (max 100, exact match); **at least one of the two is required**, otherwise 400 with `fields.q`; `page`, `size` as above; response has `meta` |
| GET | `/categories` | 200 | distinct categories of active products, sorted, as a list of strings |
| GET | `/products/{id}` | 200 | 404 if unknown or deactivated |
| POST | `/products` | 201 | body: `sku`, `name`, `priceCents`, `stock`, optional `description`, `category`, `weightKg` |
| PUT | `/products/{id}` | 200 | full replace; same body as create **plus a required `version`** (see [Concurrent edits](#concurrent-edits)) |
| DELETE | `/products/{id}` | 204 | soft delete (deactivate) |
| POST | `/purchases` | 201 | see below |
| GET | `/orders/{id}` | 200 | an order as returned by `POST /purchases` |

Unknown body keys are rejected.

### Concurrent edits

Every product carries an integer `version` (starts at 1). It is bumped by every write to the row: an edit (`PUT`), a soft delete, and the stock decrement of each purchase. `PUT /products/{id}` must send the `version` the client last read; the update only applies if that is still the stored version (`UPDATE ... WHERE id = ? AND version = ?`). Otherwise nothing is written and the API returns `409` with `error.type` `CONFLICT`, the description "Product was modified by someone else; reload and try again." and `error.fields.version` set. A duplicate SKU is also `409 CONFLICT` but has **no** `fields`, which is how clients tell the two apart. An unknown or deactivated id is still `404` (checked before the version), and a missing or non-integer `version` is `400` with `fields.version`.

This prevents the lost update where an admin opens a product at stock 50, customers buy 10, and the admin's save would silently put stock back to 50: the save is rejected and the admin reloads.

**Compatibility:** `version` is required on `PUT`, so API clients written against the earlier documented body (create fields only) now get `400` until they send it. It is required deliberately: an optional version would leave the lost update possible for any client that omits it. `POST` rejects `version` as an unknown key. Take it from `GET /products/{id}` (or any product in a list).

Existing databases need the column once (idempotent, no data loss): `docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < backend/db/migrations/001-add-product-version.sql`. Fresh volumes get it from `backend/db/init/01-schema.sql`.

### Pagination

`GET /products` and `GET /products/search` return `data` as a bare array and add a top-level `meta` object with the page and size used and the total number of matching active products:

```json
{ "statusCode": 200,
  "data": [ { "id": 1, "sku": "RS-001", "name": "Running Shoes" } ],
  "meta": { "page": 1, "size": 20, "total": 88 } }
```

A page past the end returns `data: []` with the same `total`. Other endpoints have no `meta` key. A blank or whitespace-only `q` counts as absent.

### Purchases

```bash
curl -X POST http://localhost:8080/api/purchases \
  -H 'Content-Type: application/json' \
  -d '{"items":[{"productId":1,"quantity":2}]}'
```

`items` has 1 to 50 entries, each with a unique integer `productId` >= 1 and an integer `quantity` from 1 to 1000. The response is `201` with the order, whose `status` is `PAID` or `FAILED`:

```json
{ "statusCode": 201,
  "data": { "id": 1, "status": "PAID", "totalCents": 18998,
            "createdAt": "2026-11-01T23:38:58+00:00",
            "items": [ { "productId": 1, "sku": "RS-001", "name": "Running Shoes",
                         "quantity": 2, "unitPriceCents": 9499 } ] } }
```

A declined payment is **not** an HTTP error: the order is returned with `status: "FAILED"`, and stock is left untouched. With the fake gateway, a total of exactly 666 cents (6.66) is always declined.

### Errors

Every error uses the same envelope:

```json
{ "statusCode": 400,
  "error": { "type": "VALIDATION_ERROR",
             "description": "The request is invalid.",
             "fields": { "items.0.quantity": "0 must be between 1 and 1000" } } }
```

`fields` is present only for field-level validation errors. For example, `GET /products/search` with neither `q` nor `category`:

```json
{ "statusCode": 400,
  "error": { "type": "VALIDATION_ERROR",
             "description": "The request is invalid.",
             "fields": { "q": "Provide q or category." } } }
```

| Status | `error.type` | When |
|---|---|---|
| 400 | `VALIDATION_ERROR` | Malformed JSON, wrong types, unknown keys, bad route/query params, business validation (e.g. negative price) |
| 404 | `RESOURCE_NOT_FOUND` | Unknown product or order, or a purchase referencing an unknown or deactivated product |
| 409 | `CONFLICT` | Duplicate SKU (no `fields`), or a stale `version` on `PUT /products/{id}` (`fields.version` set) |
| 409 | `INSUFFICIENT_STOCK` | Not enough stock for a purchase line |
| 503 | `SERVICE_UNAVAILABLE` | A purchase hit a database deadlock or lock-wait timeout on all 3 attempts (the API retries transient errors itself, with a short jittered backoff, before giving up). Nothing was charged or decremented; send the same request again. Has a `Retry-After: 1` header |
| 500 | `SERVER_ERROR` | Unexpected failure |

## Roadmap

- [x] Schema: `active` on `products`, new `orders` and `order_items` tables
- [x] Entities and domain exceptions
- [x] `ProductRepository` and `ProductService` (CRUD + search)
- [x] `PaymentGateway` + fake implementation
- [x] `PurchaseService` and `OrderRepository`
- [x] API routes and actions (see [API](#api))
- [x] Frontend: product list, search, product form, checkout
- [x] Backend tests (`make check` runs phpcs, phpstan and phpunit)
