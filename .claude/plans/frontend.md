# Frontend Plan: React product catalog, product form and checkout

Scope: the React app in `frontend/` on top of the finished HTTP API (including the additions from `.claude/plans/backend-gaps.md`: `meta.total`, `GET /categories`, optional `q` on search). The earlier "no new backend endpoints" constraint is superseded by that plan.

Delivered in five phases, run in order. Each phase ends with passing tests and something that runs in the browser; do not start the next phase until the current one's acceptance checks pass.

## Existing context
- `frontend/` is a Vite + React 18 scaffold: `src/App.jsx` renders "Hello, World!", `src/main.jsx` mounts it. No router, no tests, no styles.
- nginx serves the build and forwards `/api/*` to PHP-FPM (prefix stripped). In the browser the app always calls relative `/api/...`, so there is no CORS or base-URL config in production.
- `npm run dev` runs Vite on its own port, so `vite.config.js` needs a dev proxy for `/api` to reach the API (see Phase 1).
- API contract and error envelope are documented in the README. Money is integer cents everywhere; dates are ISO 8601.
- Seeded names include SQL/XSS test strings kept as plain text. React escapes text by default; never use `dangerouslySetInnerHTML`.

## API gaps to account for
| Gap | Consequence | Handling |
|---|---|---|
| Deactivated products 404 | Deleting an item removes it from every view | Treat delete as removal; refetch the list after it. |

The earlier gaps (no total, no category list, search requiring `q`) are closed by `.claude/plans/backend-gaps.md`. Beyond that plan, do not add backend endpoints without asking; if a gap hurts, list it as a follow-up.

## Libraries
Kept deliberately small.
| Need | Choice | Why |
|---|---|---|
| Routing | `react-router-dom` `^6` | Product list, product form and checkout are separate pages with deep links. |
| Data fetching | plain `fetch` in `src/api/` + small hooks | Six endpoints; a query library is more weight than value. |
| Cart state | React Context + `useReducer`, persisted to `localStorage` | No state library needed; survives reload. |
| Styling | Plain CSS (one global file + CSS variables) | No UI dependency to maintain. |
| Tests | `vitest`, `@testing-library/react`, `@testing-library/user-event`, `jsdom` | Native to Vite. |

Verify each version resolves before relying on it; pin to the current stable line of each.

## Structure
```
frontend/src/
  main.jsx
  App.jsx                    routes + layout shell
  api/
    client.js                request(), ApiError
    products.js              list, search, get, create, update, remove
    categories.js            listCategories
    purchases.js             createPurchase
  cart/
    CartContext.jsx          reducer, provider, useCart
  components/                Layout, ProductCard/Table, Pagination, FieldError, Spinner, ErrorBanner
  pages/
    ProductsPage.jsx         list + search
    ProductFormPage.jsx      create + edit
    CartPage.jsx             cart + checkout
    OrderResultPage.jsx      PAID / FAILED outcome
    NotFoundPage.jsx
  lib/
    money.js                 formatCents(), parsePriceToCents()
  styles.css
```

## Routes
| Path | Page |
|---|---|
| `/` | Redirect to `/products` |
| `/products` | `ProductsPage` (`?q=`, `?category=`, `?page=` in the URL so state survives reload and is shareable) |
| `/products/new` | `ProductFormPage` (create) |
| `/products/:id/edit` | `ProductFormPage` (edit) |
| `/cart` | `CartPage` |
| `/orders/:id` | `OrderResultPage` (reads `GET /orders/{id}`, so it works on reload) |
| `*` | `NotFoundPage` |

## API client contract
`request(method, path, body?)` in `api/client.js`:
- Prefixes `/api`, sends `Content-Type: application/json` when there is a body, parses JSON.
- Unwraps the envelope: returns `data` on success; `204` returns `null`.
- On non-2xx throws `ApiError { status, type, description, fields }` taken from `error`. Network failures and non-JSON responses (e.g. an nginx 502 HTML page) throw `ApiError` with `type: 'NETWORK_ERROR'` / `'SERVER_ERROR'`, never a raw `SyntaxError`.
- Pages branch on `error.type`: `VALIDATION_ERROR` (map `fields` onto inputs), `RESOURCE_NOT_FOUND`, `CONFLICT`, `INSUFFICIENT_STOCK`.
- Server `fields` keys can be nested paths such as `items.0.quantity`; the helper that maps them to inputs must handle that form.

## Money
- Display: `formatCents(1999)` returns `$19.99` using `Intl.NumberFormat`. Currency is USD (the seed is USD; confirm).
- Input: the form takes a decimal string and converts to integer cents with string/integer arithmetic, not `parseFloat(x) * 100` (floating-point errors). Reject more than two decimals, negatives and non-numeric input client-side; the server still has the final say.

---

## Phase 1: Foundation
Everything later copies the client, error handling and layout set here, so review this phase most closely.

**Steps**
1. Install the dependencies above. Add `test`/`lint`-style scripts: `"test": "vitest run"`.
2. `vite.config.js`: add `server.proxy` for `/api` to the API, stripping the prefix to match nginx (target configurable via env, default `http://localhost:8080` so it works against `docker compose up`). Add the vitest config (`jsdom`, setup file).
3. `api/client.js` with `request` and `ApiError` as specified above; `lib/money.js`.
4. `App.jsx` with the router, a `Layout` (header nav: Products, New product, Cart with item count) and `NotFoundPage`. Shared `Spinner`, `ErrorBanner`, `FieldError` components.
5. `styles.css` with CSS variables, basic responsive layout (works at phone width).

**Tests**
- `client`: success unwrap, 204, each error type maps to `ApiError` with `fields`, network failure, HTML error body.
- `money`: `formatCents` edge cases (0, 5, 100, large); `parsePriceToCents` accepts `"19.99"`, `"19"`, `"0.1"`; rejects `"19.999"`, `"-1"`, `"abc"`, `""`, `"1e3"`.
- Router smoke test: unknown path renders `NotFoundPage`.

**Done when**: `npm test` and `npm run build` pass, and `npm run dev` shows the layout with working navigation.

---

## Phase 2: Product list and search

**Steps**
1. `api/products.js`: `listProducts({page,size})`, `searchProducts({q,category,page,size})`, `getProduct(id)`; both list calls return `{ items, meta }` (`meta.page`, `meta.size`, `meta.total`). `api/categories.js`: `listCategories()`.
2. `useProducts` hook (a single hook): reads `q`, `category`, `page` from the URL; calls search whenever `q` or `category` is set (after trimming) and list otherwise; exposes `{items, total, loading, error}`; ignores stale responses (abort the previous request or compare a request counter) so fast typing cannot show old results.
3. `ProductsPage`: search box (submit on Enter or debounced ~300 ms, resets `page` to 1), results table/cards (name, sku, category, price, stock), a category dropdown filled from `GET /categories` (with an "All" option; changing it resets `page` to 1), and `Pagination` with real pages from `meta.total` ("page X of Y", `Y = ceil(total / size)`; if the page is past the end, step back to the last page), empty state ("No products match"), loading and error states.
4. Each row has Edit (link), Delete (Phase 3), and Add to cart (Phase 4, disabled when `stock` is 0). Add placeholders now only if they render nothing; do not ship dead buttons.

**Tests** (fetch mocked): initial load renders rows; typing a query calls search with `q` and resets to page 1; empty `q` and no category falls back to list; a category alone calls search; "page X of Y" comes from `meta.total` and Next is disabled on the last page; the category dropdown is populated from `/categories`; error shows `ErrorBanner`; a hostile name like `<script>alert(1)</script>` renders as text.

**Done when**: against the real stack the 90 seeded products page correctly, search works, and the tests pass.

---

## Phase 3: Product form and delete

**Steps**
1. `api/products.js`: `createProduct`, `updateProduct` (full replace), `deleteProduct`.
2. `ProductFormPage` handles both modes. Edit mode loads the product first (404 shows a not-found message). Fields: `sku`, `name`, `description`, `category` (free text with a `<datalist>` of suggestions from `GET /categories`, so a new category can still be typed), `price` (dollars, converted via `parsePriceToCents`), `stock`, `weightKg` (optional).
   - Client-side checks mirror the server limits (sku 1..32, name 1..255, category <= 100, price >= 0, stock integer >= 0, weight >= 0) for fast feedback; the server remains authoritative.
   - Send exactly the keys the API accepts (it rejects unknown keys); send `null`, not `""`, for empty optional fields.
   - On `VALIDATION_ERROR` map `error.fields` onto the matching inputs; on `CONFLICT` show "SKU already exists" on the `sku` field.
   - Disable submit while in flight to prevent double submit. On success navigate to `/products`.
3. Delete: confirm dialog (native `confirm` is acceptable), call `DELETE`, refetch the list. A 404 on delete counts as already gone.

**Tests**: create happy path sends the expected body (price converted to cents, empty optionals as `null`); edit pre-fills and sends `PUT`; server field errors show next to inputs; duplicate SKU shows the sku error; submit is disabled while pending; delete refetches; unknown id on edit shows not-found.

**Done when**: a product can be created, edited, searched for and deleted through the real stack, and the tests pass.

---

## Phase 4: Cart and checkout

**Steps**
1. `CartContext`: state `{ [productId]: { product snapshot (id, name, sku, priceCents, stock), quantity } }`; actions `add`, `setQuantity`, `remove`, `clear`. Quantity clamps to `1..min(stock, 1000)` in the UI; duplicate adds merge into one line (the API rejects duplicate `productId`s). Persist to `localStorage` inside try/catch and render correctly without it; ignore malformed stored data.
2. `CartPage`: lines with quantity editor and remove, line totals and grand total (integer cents), empty-cart state, "Pay now" button. Cart is capped at 50 lines (API limit); show a message at the cap.
3. `api/purchases.js`: `createPurchase(items)` sending `{ items: [{ productId, quantity }] }` only.
4. Submit flow, disabled while in flight:
   - `201` with `status: "PAID"`: clear the cart, navigate to `/orders/:id` with the order.
   - `201` with `status: "FAILED"`: **not an error**. Keep the cart, show the declined result with a "Try again" action. No stock was taken.
   - `409 INSUFFICIENT_STOCK` / `404` (product gone): keep the cart, show the server message, and offer to refresh the cart's prices and stock from `getProduct` (drop lines whose product is gone).
   - `400 VALIDATION_ERROR`: map `fields` like `items.0.quantity` to the matching line.
5. `OrderResultPage` loads `GET /orders/{id}` and shows status, line items (name, sku, quantity, unit price), total and created date. Needs the `GET /orders/{id}` endpoint, which exists.
6. Header cart badge shows the total quantity.

**Tests**: reducer unit tests (add merges, clamp, remove, clear); persistence round-trip and bad stored data; PAID clears the cart and navigates; FAILED keeps the cart and shows the declined state; insufficient stock keeps the cart and shows the message; field error maps to the right line; double click sends one request.

**Done when**: an end-to-end purchase works against the real stack for both outcomes (use a cart totalling exactly $6.66 to trigger the fake gateway's decline), and the tests pass.

---

## Phase 5: Wrap-up

**Steps**
1. Accessibility pass: labelled inputs, `aria-live` on error and result messages, visible focus, keyboard-only walkthrough of search, form and checkout.
2. Responsive check at phone width; fix overflow in the table (cards or horizontal scroll inside the table container).
3. Verify with `docker compose up --build`: SPA fallback on deep-link reload (`/products/3/edit`, `/orders/1`), `/api` through nginx, the production build has no dev-proxy dependency.
4. Make sure the Dockerfile build runs from the lockfile (`npm ci`) and that tests are not required to build the image.
5. Update the README: tick the frontend roadmap item, change the status banner, extend "Frontend development" with the proxy note, `npm test`, and the structure above.

**Done when**: the full flow (browse, search, create, edit, delete, buy PAID, buy FAILED) works from a clean `docker compose down -v && docker compose up --build`, tests pass, and the README is current.

---

## Decisions (defaults used in this plan)
- Plain `fetch` and Context, not a query/state library; `react-router-dom` for routing.
- Plain JavaScript (the scaffold is `.jsx`), no TypeScript.
- Pagination uses `meta.total` from the API ("page X of Y"); one `useProducts` hook calls search whenever `q` or `category` is set.
- The category filter is a dropdown from `GET /categories`.
- Search and filter state lives in the URL query string.
- A declined payment is an expected result screen, not an error banner.
- Currency is USD; prices are entered as dollars and sent as integer cents.
- Delete uses a native confirm and refetches the list.
- The cart is client-only (localStorage); there are no user accounts.

## Out of scope
Authentication and user accounts, server-side cart, real payment form (card details), order history list (no list endpoint), product images, i18n, TypeScript migration, end-to-end browser tests (Playwright), and any backend work beyond `.claude/plans/backend-gaps.md`.
