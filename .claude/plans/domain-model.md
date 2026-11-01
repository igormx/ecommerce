# Domain Plan: Option 1 (anemic entities, repository per entity)

Scope: domain side only, aligned with the existing backend (PHP 8, Slim 4, PHP-DI, MySQL 8.4).
No routes, actions, or frontend yet.

## Existing context
- Namespace `App\` -> `backend/src`. Layers: `Domain`, `Application`, `Infrastructure` (Infrastructure is empty).
- Repository wiring goes in `backend/app/repositories.php` (currently commented out).
- `products` table already exists (`backend/db/init/01-schema.sql`) and is seeded with 90 rows (`02-seed.sql`).
- Seed data rules (see `DATA_CLEANING_LOG.md`): some products have NULL category or NULL weight; a few names are XSS/SQL-injection test strings kept as plain text (queries must be parameterized, output escaped).
- `Domain/User` is a leftover from the skeleton; follow its shape (small class + repository interface + not-found exception).

## Entities (data only, no behavior)
Mapped to the existing `products` table:

- **Product**: id (int), sku (string, unique), name, description (nullable), category (nullable), priceCents (int), stock (unsigned int), weightKg (nullable), active (bool), createdAt, updatedAt

Schema change: add `active TINYINT(1) NOT NULL DEFAULT 1` (plus an index) to `products`.

New tables (to add to the init SQL):

- **Order** (`orders`): id, status (PAID | FAILED), totalCents, createdAt
- **OrderItem** (`order_items`): id, orderId, productId, productName, sku, quantity, unitPriceCents

Relationships: `Order 1 ── * OrderItem * ── 1 Product`
- `order_items.product_id` is a foreign key to `products.id`.
- `order_items` snapshots `productName`, `sku` and `unitPriceCents` at purchase time.

## Repositories
Interfaces in `Domain/<Entity>/`, MySQL (PDO) implementations in `Infrastructure/Persistence/<Entity>/`.

- **ProductRepository**: findAll(page, size), findById, findBySku, save (insert or update), deactivate(id), search(query, category, page, size), findByIds
- **OrderRepository**: save (order + items), findById
- OrderItem has no repository; it is saved through Order.

`search` uses the existing FULLTEXT index (`MATCH(name, description, category) AGAINST (? ...)`) with bound parameters, plus an optional exact category filter. Listing and search return only `active = 1` products.

## Domain services
- **ProductService**: CRUD + search. Validation: name required, sku required and unique, priceCents >= 0, stock >= 0, weightKg >= 0 if present.
- **PaymentGateway** (interface): `charge(amount)`. `FakePaymentGateway` approves by default and declines on a magic input.
- **PurchaseService**: load products, check stock, snapshot name/sku/price into OrderItems, charge via PaymentGateway, decrement stock on success, save Order as PAID or FAILED.

## Domain exceptions
Extend the existing `DomainException` / `DomainRecordNotFoundException`:
ProductNotFoundException, OrderNotFoundException, DuplicateSkuException, InsufficientStockException, PaymentDeclinedException (or a FAILED order result).

## Rules
- Money is integer cents in the domain (`priceCents`, `totalCents`, `unitPriceCents`). The existing `products.price` stays `DECIMAL(10,2)`; the repository converts at the boundary (cents = price * 100 via string/round math, never float arithmetic). New order tables store cents as unsigned ints.
- Delete is soft: `deactivate` sets `active = 0`. Inactive products can't be purchased, but existing order items still reference them.
- OrderItem snapshots name, sku and unit price at purchase time.
- Stock is only decremented when payment succeeds, in the same DB transaction as saving the Order.
- Stock decrement is atomic (`UPDATE ... SET stock = stock - ? WHERE id = ? AND stock >= ?`) to avoid overselling.
- A FAILED order never touches stock.
- All SQL uses bound parameters.

## Steps
1. Add `active` to `products`, and add `orders` and `order_items` tables, in the init SQL.
2. Define `Product`, `Order`, `OrderItem` entities and domain exceptions.
3. Define the repository interfaces.
4. Implement `ProductService` and the MySQL `ProductRepository` (CRUD + search).
5. Define `PaymentGateway` + `FakePaymentGateway`.
6. Implement `PurchaseService`, `OrderRepository`, and the DI wiring in `app/repositories.php`.

## Decisions made
- **Product delete:** soft delete via an `active` column.
- **Money in PHP:** integer cents.

## Open
- **Schema changes on an existing volume:** init scripts only run on a fresh MySQL volume, so adding `active`, `orders` and `order_items` needs a volume reset or a migration.
