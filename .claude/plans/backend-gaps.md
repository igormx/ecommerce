# Backend Gaps Plan: pagination totals, categories endpoint, optional search query

Scope: three small API additions that the frontend plan (`.claude/plans/frontend.md`) depends on. Backend only; no frontend work except the plan update in the last phase.

Delivered in three phases, run in order. Each phase ends with passing tests and something that runs; do not start the next phase until the current one's acceptance checks pass. The phases are independent of each other, so they can ship as separate commits.

## Existing context
- `ProductRepositoryInterface` has `findAll`, `search`, `findByIds`, etc. `MySqlProductRepository` is unit-tested against a mocked PDO (`tests/Infrastructure/Product/MySqlProductRepositoryTest.php`); `tests/Support/InMemoryProductRepository.php` implements the same interface for service tests. Any interface change must be mirrored in both.
- `ProductService::list()` and `search()` return `Product[]` and clamp `page` (max `MAX_PAGE`) and `size` (max `MAX_PAGE_SIZE`). `normalize` helpers (`trim`, `nullIfBlank`) live in the service.
- `MySqlProductRepository::search()` already handles an empty query (it omits the `MATCH ... AGAINST` clause and orders by id) and an optional `category`. Only the Action layer blocks an empty `q`.
- `Action::getSearchTerm()` requires `q` (non-empty, max 255); `getCategory()` is optional (max 100).
- Responses use the `ActionPayload` envelope (`statusCode`, `data`, `error`). `data` for `GET /products` and `/products/search` is a bare JSON array.
- `products.category` is `VARCHAR(100) NULL` with `idx_products_category`; the table also has a FULLTEXT index on `(name, description, category)`.
- Tests: `tests/Application/Actions/Product/ProductReadEndpointsTest.php` exercises the endpoints through the real Slim app. `make check` runs phpcs, phpstan and phpunit.

## Design decisions
| Question | Decision | Why |
|---|---|---|
| Where does `total` go? | A new optional top-level **`meta`** object in the envelope: `{ "page": 1, "size": 20, "total": 88 }`. `data` stays a bare array. | Backwards compatible (existing consumers and tests keep working), no CORS `Expose-Headers` needed (a header like `X-Total-Count` would need it), and serialized only when set, like `error.fields`. |
| Count in the same query? | No: a separate `COUNT(*)` with the identical `WHERE`. | Simpler than `SQL_CALC_FOUND_ROWS` (deprecated) or window functions; fine for the data size. The two reads are not atomic; an off-by-one under concurrent writes is acceptable. |
| Does `total` include inactive products? | No, active only, same filter as the page query. | The list never shows inactive products. |
| Service shape | Add `count()` and `countSearch()` next to `list()` and `search()`; keep the existing return types. | Does not break existing service tests or callers. Both count methods apply the same normalization as their page counterparts so the numbers always agree. |
| Page past the end | Returns `data: []` with a correct `total`. | The client can detect it and step back. |
| Categories endpoint | `GET /categories` returns `data: ["Audio", "Footwear", ...]`: distinct, non-null, active products only, sorted. | Enough to fill a dropdown; no new entity. |
| Empty search | `q` becomes optional on `/products/search`, but **at least one of `q` or `category` is required**; otherwise 400 `VALIDATION_ERROR` with `fields.q`. | Avoids a second spelling of `GET /products`; the filter-only case is what the UI needs. |

## Error and edge cases to keep
- `q` present but whitespace-only counts as absent.
- `category` with no `q` is exact match (as the repository does today); `q` is still FULLTEXT natural-language search.
- Category values come from the database, so they can contain the seed's SQL/XSS test strings: always bound parameters, always output as JSON strings.

---

## Phase 1: Pagination totals
The only contract change: it adds a field to the envelope, so review this phase most closely.

**Steps**
1. `ActionPayload`: add an optional `meta` array, serialized only when set (mirror how `ActionError::fields` is done). Add `Action::respondWithData(..., ?array $meta = null)` or a sibling helper `respondWithPage($data, $page, $size, $total)`.
2. `ProductRepositoryInterface`: add `countAll(): int` and `countSearch(string $query, ?string $category): int`.
3. `MySqlProductRepository`:
   - `countAll()`: `SELECT COUNT(*) FROM products WHERE active = 1`.
   - `countSearch()`: reuse the `WHERE` builder used by `search()` (extract a private method that returns `[$sqlFragment, $params]` so the two cannot drift). Count query has no `ORDER BY`, `LIMIT` or `OFFSET`.
4. `InMemoryProductRepository`: implement both counts with the same filtering as its `findAll`/`search`.
5. `ProductService`: `count(): int` and `countSearch(string $query, ?string $category = null): int`, applying `trim` / `nullIfBlank` like `search()`.
6. `ListProductsAction` and `SearchProductsAction`: after loading the page, respond with `meta = { page, size, total }` using the validated `page`/`size` values.

**Files**: `ActionPayload.php`, `Action.php`, `ProductRepositoryInterface.php`, `MySqlProductRepository.php`, `ProductService.php`, `ListProductsAction.php`, `SearchProductsAction.php`, `tests/Support/InMemoryProductRepository.php`.

**Tests**
- Repository (mocked PDO): `countAll` runs the expected SQL and returns an int (PDO returns a numeric string); `countSearch` with query, with category, with both, and with neither; the count `WHERE` matches the page `WHERE`; bound parameters only.
- Service: counts agree with the in-memory page results; whitespace query/category are normalized.
- Endpoints (through Slim): `meta.total` correct for list and search; page past the end gives `data: []` and the same `total`; `meta` echoes `page`/`size`; other endpoints' envelopes have no `meta` key; existing assertions on `data` still pass.
- `ActionPayload`: `meta` omitted when null, serialized when set.

**Done when**: both list endpoints return `meta` and the tests, phpstan and phpcs are green.

---

## Phase 2: Categories endpoint

**Steps**
1. `ProductRepositoryInterface::findCategories(): array` (list of strings). SQL: `SELECT DISTINCT category FROM products WHERE active = 1 AND category IS NOT NULL ORDER BY category`. Implement in `MySqlProductRepository` and `InMemoryProductRepository`.
2. `ProductService::listCategories(): array`.
3. `ListCategoriesAction` in `Actions/Category/` (or `Actions/Product/`, matching where the other read actions sit), returning `data` as a list of strings. No query params; unknown params are ignored as elsewhere.
4. Route: `$app->get('/categories', ListCategoriesAction::class);` in `app/routes.php`. DI autowires it.

**Files**: `ProductRepositoryInterface.php`, `MySqlProductRepository.php`, `ProductService.php`, new `ListCategoriesAction.php`, `app/routes.php`, `tests/Support/InMemoryProductRepository.php`.

**Tests**
- Repository (mocked PDO): expected SQL, returns `list<string>`, empty table gives `[]`.
- Service: distinct, sorted, excludes null/inactive (in-memory repository).
- Endpoint: 200 with an array of strings; deactivating the only product in a category removes it; empty database gives `[]`.

**Done when**: `GET /categories` returns the seeded categories through the real stack, and tests, phpstan and phpcs are green.

---

## Phase 3: Optional search query, docs, and frontend plan update

**Steps**
1. `Action`: add `getOptionalSearchTerm(): ?string` (same rules as `getSearchTerm()`: string, max 255; blank or whitespace-only becomes `null`). Keep `getSearchTerm()` if other code uses it; remove it if `SearchProductsAction` was the only caller.
2. `SearchProductsAction`: read both values; when `q` is `null` and `category` is `null`, throw `ValidationException(['q' => 'Provide q or category.'])` (the standard 400 with `fields`). Otherwise call the service with `q ?? ''`.
3. Verify the whole flow against the Docker stack with curl: `/api/products/search?category=...`, `/api/products/search?q=...&category=...`, `/api/categories`, `meta.total` on both list routes, and the 400 for neither.
4. README: update the API table (`GET /categories`, `q` optional but one of `q`/`category` required), document `meta` in a short "Pagination" subsection, and add the new `error.fields` example.
5. Update `.claude/plans/frontend.md`:
   - Remove the three gap rows ("no total", "no category list", "search requires q") and the "Prev/Next only" decision.
   - Phase 2: real pagination from `meta.total` ("page X of Y"); a single `useProducts` hook that calls search whenever `q` or `category` is set.
   - Phase 3 and 2: category dropdown from `GET /categories` (add `api/categories.js`); the product form can offer the same list as suggestions via `<datalist>` while still allowing a new category.
   - Mark "no new backend endpoints" as superseded by this plan.

**Files**: `Action.php`, `SearchProductsAction.php`, `README.md`, `.claude/plans/frontend.md`.

**Tests**
- Endpoint: `category` only returns the filtered page with `meta.total`; `q` only still works; both work; neither gives 400 with `fields.q`; whitespace-only `q` plus no category gives 400; whitespace-only `q` plus a category behaves like category only; over-long `q` (256) and `category` (101) still 400.
- Update the existing "missing `q`" test to the new contract, and say so in the commit message.
- Action helper: `getOptionalSearchTerm` cases (absent, blank, valid, non-string, too long).

**Done when**: the new search contract is covered by passing tests, the curl checks against the Docker stack match the README, and the frontend plan no longer lists these gaps.

---

## Decisions (defaults used in this plan)
- `meta` in the envelope, not a header and not a wrapped `data` object.
- Separate `COUNT(*)` query that shares its `WHERE` with the page query.
- Counts are active products only.
- `GET /categories` returns a plain sorted list of distinct non-null category strings.
- Search requires at least one of `q` or `category`; `/products` stays the way to list everything.
- No change to write endpoints, purchases, or the DB schema.

## Out of scope
Category as a first-class entity (table, ids, CRUD), per-category counts, cursor-based pagination, sorting options, caching of the category list, case-insensitive or partial category matching, and any frontend implementation beyond updating the frontend plan.
