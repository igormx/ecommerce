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

The app is served at http://localhost:8080. nginx serves the frontend and forwards `/api/*` to the PHP-FPM container (the `/api` prefix is stripped before reaching Slim).

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

## Frontend development

```bash
cd frontend
npm install
npm run dev     # Vite dev server on http://localhost:5173
npm test        # Vitest + Testing Library (jsdom)
npm run build   # production build into dist/
```

Source layout in `frontend/src/`: `api/` (fetch client and endpoint modules), `cart/` (client-only cart in a Context, persisted to localStorage), `components/` (layout, shared product browser, table, pagination, error and spinner), `hooks/` (`useProducts`), `lib/` (money helpers; prices are integer cents), `pages/` (routes) and `styles.css`. Search and filter state lives in the URL query string.

Routes: `/products` is the shopper storefront (search, filter, add to cart); `/admin/products` is the admin list (edit, delete, new), with the form at `/admin/products/new` and `/admin/products/:id/edit` (the old `/products/new` and `/products/:id/edit` redirect there). There is no authentication, so the storefront/admin split is a UX separation only, not access control.

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

## API

All routes are served under `/api` by nginx (the prefix is stripped before Slim, so the routes below are registered without it). Requests and responses are JSON. Money is always integer cents (`priceCents`, `totalCents`, `unitPriceCents`). Dates are ISO 8601.

| Method | Path | Success | Notes |
|---|---|---|---|
| GET | `/products` | 200 | `page` (default 1, max 1000000), `size` (default 20, max 100); response has `meta` |
| GET | `/products/search` | 200 | `q` (max 255) and `category` (max 100, exact match); **at least one of the two is required**, otherwise 400 with `fields.q`; `page`, `size` as above; response has `meta` |
| GET | `/categories` | 200 | distinct categories of active products, sorted, as a list of strings |
| GET | `/products/{id}` | 200 | 404 if unknown or deactivated |
| POST | `/products` | 201 | body: `sku`, `name`, `priceCents`, `stock`, optional `description`, `category`, `weightKg` |
| PUT | `/products/{id}` | 200 | full replace; same body as create |
| DELETE | `/products/{id}` | 204 | soft delete (deactivate) |
| POST | `/purchases` | 201 | see below |
| GET | `/orders/{id}` | 200 | an order as returned by `POST /purchases` |

Unknown body keys are rejected.

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
| 409 | `CONFLICT` | Duplicate SKU |
| 409 | `INSUFFICIENT_STOCK` | Not enough stock for a purchase line |
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
