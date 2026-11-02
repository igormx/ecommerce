# HTTP Actions Plan: Slim Actions + request validation with respect/validation

Scope: the HTTP layer on top of the existing `ProductService` and `PurchaseService`. No frontend work.

Delivered in four phases, run in order. Each phase ends with passing tests and something that runs; do not start the next phase until the current one's acceptance checks pass.

## Existing context
- Services, repositories and domain exceptions are done and tested. `app/routes.php` has no API routes; `app/dependencies.php` / `app/repositories.php` wire the DI container.
- `Application/Actions/Action.php` is the skeleton base class. Its `__invoke` only maps `DomainRecordNotFoundException` to `HttpNotFoundException`. `ActionPayload` / `ActionError` define the JSON envelope. `HttpErrorHandler` renders everything else.
- `public/index.php` already adds body parsing middleware (`$app->addBodyParsingMiddleware()`), so `getParsedBody()` returns an array for JSON, and `null` for malformed JSON or other content types.
- nginx strips `/api`, so routes are registered without the prefix.
- `ProductService` and `PurchaseService` own the business rules and throw `ValidationException` (`field => message`), `InvalidPurchaseException`, etc. Actions must not duplicate them.
- Composer is not installed on the host; run it in the api container or the Docker build.

## Validation split
| Layer | Responsibility |
|---|---|
| Action | Shape and type of the request: body is a JSON object, required keys, strict types, string lengths, numeric bounds, no unknown keys, path/query params. Converts JSON into the typed arguments the services need. |
| Service | Business rules: non-empty name/sku, price/stock/weight >= 0, SKU uniqueness, stock availability, purchase lines. Also the DB-protecting length/upper-bound checks (added in Phase 3). |

Rule of thumb: if the failure would otherwise be a `TypeError` or a MySQL error (500), the Action catches it. If it is a business rule, the service does.

## Library
`respect/validation`, pinned to `^2.4` (the stable 2.x line; supports PHP 8.3 and the fluent `v::` API). Verify the constraint resolves, and that the rule names used below exist in the installed version, before relying on them.

## Error format
- Field errors: **400** with the standard payload and a new `ActionError::VALIDATION_ERROR` type, plus per-field messages:
  ```json
  { "statusCode": 400,
    "error": { "type": "VALIDATION_ERROR",
               "description": "The request is invalid.",
               "fields": { "priceCents": "must be an integer", "sku": "..." } } }
  ```
- Unparseable body (`getParsedBody()` is not an array/object): **400** with the same `VALIDATION_ERROR` type, no `fields`.
- `ValidationException` (service) uses the same 400 body, so Action-level and service-level failures look identical.
- `ActionError` gains an optional `fields` array that is serialized only when set.

`HttpErrorHandler` mapping (single place, so Actions stay thin):

| Exception | Status | ActionError type |
|---|---|---|
| `ValidationException` | 400 | VALIDATION_ERROR (with `fields`) |
| `InvalidPurchaseException` | 400 | VALIDATION_ERROR |
| `ProductNotFoundException` / `OrderNotFoundException` | 404 | RESOURCE_NOT_FOUND |
| `DuplicateSkuException` | 409 | CONFLICT |
| `InsufficientStockException` | 409 | INSUFFICIENT_STOCK |
| Declined payment | not an error: 201 with the FAILED order in the body |

The not-found case already works through `DomainRecordNotFoundException` if the product/order not-found exceptions extend it; confirm.

## Endpoints
| Method | Path | Action | Success | Phase |
|---|---|---|---|---|
| GET | `/products/{id}` | `ViewProductAction` | 200 | 2 |
| GET | `/products` | `ListProductsAction` (`page`, `size`) | 200 | 2 |
| GET | `/products/search` | `SearchProductsAction` (`q`, `category`, `page`, `size`); registered **before** `{id}` | 200 | 2 |
| POST | `/products` | `CreateProductAction` | 201 | 3 |
| PUT | `/products/{id}` | `UpdateProductAction` (full replace) | 200 | 3 |
| DELETE | `/products/{id}` | `DeactivateProductAction` | 204 | 3 |
| POST | `/purchases` | `CreatePurchaseAction` | 201 (PAID or FAILED order) | 4 |
| GET | `/orders/{id}` | `ViewOrderAction` (optional) | 200 | 4 |

Routes go in `app/routes.php`. Actions are autowired by PHP-DI (they need the service and the logger).

---

## Phase 1: Foundation
No routes yet. Everything later copies the error format and rule style set here, so review this phase most closely.

**Steps**
1. Add `respect/validation:^2.4` to `backend/composer.json` and rebuild the api image. Smoke-test that `v::intType()->validate('12')` is false.
2. Add `ActionError::VALIDATION_ERROR` / `CONFLICT` / `INSUFFICIENT_STOCK` types and the optional `fields`. Extend `HttpErrorHandler` with the mapping table above.
3. Build `Application/Actions/Validation/RequestValidator`, a thin adapter over respect/validation:
   - `validate(array $data, array $rules, bool $rejectUnknown = true): array`, where `$rules` maps `field => Validatable` (with required / optional / nullable distinguished).
   - Runs every rule, collects all messages (not just the first), and throws `ValidationException` with `field => message`. Uses `NestedValidationException::getMessages()`, flattened to one string per field.
   - Rejects unknown keys with message "unknown field".
   - Returns the validated array so callers read typed values from it.
4. Extend the base `Action`:
   - `getJsonBody(): array`, throwing a 400 `VALIDATION_ERROR` when the parsed body is not an array.
   - `getIntArg(string $name): int`: positive-int route arg (args arrive as strings, so validate with a digit rule and cast), 400 on failure.
   - Query-param helpers for `page`, `size` (positive ints, size max 100 to match the service clamp), `q` (non-empty string, max 255) and `category` (optional string, max 100).

**Files**: `backend/composer.json` / `composer.lock`, `ActionError.php`, `HttpErrorHandler.php`, new `Actions/Validation/RequestValidator.php`, `Actions/Action.php`.

**Tests**
- `RequestValidator`: wrong types (including that `"12"`, `12.5` and `true` are rejected for an int field), `null`, missing keys, unknown keys, boundaries, multiple errors at once.
- `HttpErrorHandler`: one test per row of the mapping table, including the `fields` serialization.
- `ActionTest`: `getJsonBody` and the id/query helpers.

**Done when**: the unit tests above pass, `composer.lock` is updated, and phpstan and phpcs are clean.

---

## Phase 2: Products read slice
First end-to-end slice. It proves the error handler, DI wiring and serialization before any write endpoint depends on them.

**Steps**
1. `ViewProductAction` + route + DI wiring, as the vertical slice. Test it through the real Slim app first.
2. Serialization: entities to JSON via a small `ProductResource` mapper (or in the Action). Money is exposed as `priceCents` (int). Dates in ISO 8601.
3. `ListProductsAction` (`page`, `size`) and `SearchProductsAction` (`q`, `category`, `page`, `size`). Register `/products/search` **before** `/products/{id}`.

**Files**: `app/routes.php`, new `Actions/Product/{View,List,Search}ProductAction.php`, `ProductResource`.

**Tests** (through the Slim app): 200 for each endpoint; 404 for an unknown or inactive id; 400 for `/products/abc`, `/products/0`, `page=0`, `page=abc`, `size=101`, missing `q`.

**Done when**: the three `GET` endpoints pass the tests above and tests, phpstan and phpcs are green.

---

## Phase 3: Products write
Adds the validation rule set for product input and protects the database at the service level.

**Steps**
1. `ProductInput::rules()` rule set, kept next to the write Actions:
   - `sku`: string, length 1..32 (after trim)
   - `name`: string, length 1..255
   - `description`: nullable string, max 65535
   - `category`: nullable string, max 100
   - `priceCents`: strict int (not `"12"`, `12.5`, `true`), 0..9_999_999_999 (fits `DECIMAL(10,2)`)
   - `stock`: strict int, 0..4_294_967_295 (`INT UNSIGNED`)
   - `weightKg`: nullable int|float, 0..99999.999
   - Unknown keys rejected.
2. `CreateProductAction` (201) and `UpdateProductAction` (200, full replace). Both validate the body, then call the service with typed arguments.
3. `DeactivateProductAction` (204).
4. Move the DB-protecting length and upper-bound checks (sku <= 32, name <= 255, category <= 100, price/stock/weight limits) into `ProductService::validate()` as well, so every caller is protected, not just HTTP. Update its tests.

**Files**: `Actions/Product/{Create,Update,Deactivate}ProductAction.php`, `ProductInput`, `ProductService.php`, `ProductServiceTest.php`.

**Tests**: happy path for each endpoint; every validation failure (wrong type, missing key, unknown key, over-length, out-of-range, multiple errors at once); duplicate SKU on create and update (409); unknown id on update and delete (404); malformed JSON body (400).

**Done when**: all write endpoints and the service limits are covered by passing tests, and no invalid input reaches MySQL as a 500.

---

## Phase 4: Purchases and wrap-up

**Steps**
1. Purchase rules for `POST /purchases`: body `{"items":[{"productId":int>0,"quantity":int 1..MAX_QTY}]}`, 1..`MAX_LINES` items. The Action rejects duplicate `productId`s (400 on `items`) and then builds the `productId => quantity` map the service expects.
2. `CreatePurchaseAction` returning 201 with the order (PAID or FAILED).
3. Optional `GET /orders/{id}` (`ViewOrderAction` + `OrderResource`), only if `OrderRepository::findById` is to be exposed.
4. Verify against the Docker stack with curl: CORS headers on real responses, the `/api` prefix through nginx.
5. Update the README roadmap and add an API section (endpoints, error format).

**Files**: `Actions/Purchase/CreatePurchaseAction.php`, purchase rules, optional `Actions/Order/ViewOrderAction.php` + `OrderResource`, `app/routes.php`, `README.md`.

**Tests**: PAID, FAILED (declined, still 201), insufficient stock (409), unknown product (404), inactive product (404), bad payload (400: empty items, non-int ids, quantity 0, duplicate productId, too many lines, unknown keys).

**Done when**: the full purchase flow works through the real stack and the tests pass; the README documents the API.

---

## Decisions (defaults used in this plan)
- 400 for every request-validation failure (bad body, bad fields, bad route or query params). Business conflicts stay 404/409.
- Unknown keys are rejected.
- Duplicate `productId` in a purchase is rejected, not merged.
- Length and upper-bound checks live in both the Action rules and the service.
- A declined payment returns a normal response containing the FAILED order, not an HTTP error.

## Out of scope
Authentication, rate limiting, request logging beyond what the error handler already does, OpenAPI docs, and frontend integration.
