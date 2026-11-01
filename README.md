# E-Commerce

A small e-commerce app: product CRUD, product search, and a "purchase" flow with a fake payment. Built for the LoanPro code challenge.

> **Status:** early stage. The infrastructure, database schema and seed data are in place. The domain, API and UI are still to be built (see [Roadmap](#roadmap)).

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

## Roadmap

- [ ] Schema: `active` on `products`, new `orders` and `order_items` tables
- [ ] Entities and domain exceptions
- [ ] `ProductRepository` and `ProductService` (CRUD + search)
- [ ] `PaymentGateway` + fake implementation
- [ ] `PurchaseService` and `OrderRepository`
- [ ] API routes and actions
- [ ] Frontend: product list, search, product form, checkout
- [ ] Tests
