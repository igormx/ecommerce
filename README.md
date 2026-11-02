# E-Commerce

A small e-commerce app: product CRUD, product search, and a "purchase" flow with a fake payment. Built for the LoanPro code challenge.

> **Status:** the backend (domain, services and HTTP API) is done. The frontend is still to be built (see [Roadmap](#roadmap)).

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
├── frontend/                React app + nginx config
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
npm run dev
```

## Data

`products` is seeded with **90 products** from the challenge CSV, which was downloaded on Friday, 2026-10-30. The CSV contained dirty data (currency symbols, `free` prices, negative stock, duplicate SKUs, empty rows, injection test strings), which is cleaned by `backend/db/seed/build_seed.py`. Every decision is recorded in [DATA_CLEANING_LOG.md](DATA_CLEANING_LOG.md).

To regenerate the seed:

```bash
python3 backend/db/seed/build_seed.py
```

Some seeded names are SQL/XSS test strings kept as plain text, so all queries must use bound parameters and all output must be escaped.

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
| GET | `/products` | 200 | `page` (default 1, max 1000000), `size` (default 20, max 100) |
| GET | `/products/search` | 200 | `q` (required, max 255), `category`, `page` (max 1000000), `size` |
| GET | `/products/{id}` | 200 | 404 if unknown or deactivated |
| POST | `/products` | 201 | body: `sku`, `name`, `priceCents`, `stock`, optional `description`, `category`, `weightKg` |
| PUT | `/products/{id}` | 200 | full replace; same body as create |
| DELETE | `/products/{id}` | 204 | soft delete (deactivate) |
| POST | `/purchases` | 201 | see below |
| GET | `/orders/{id}` | 200 | an order as returned by `POST /purchases` |

Unknown body keys are rejected.

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

`fields` is present only for field-level validation errors.

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
- [ ] Frontend: product list, search, product form, checkout
- [x] Backend tests (`make check` runs phpcs, phpstan and phpunit)
