import { useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { getProduct } from '../api/products.js';
import { createPurchase } from '../api/purchases.js';
import { MAX_LINES, maxQuantity, useCart } from '../cart/CartContext.jsx';
import ErrorBanner from '../components/ErrorBanner.jsx';
import FieldError, { fieldErrorId, fieldPath } from '../components/FieldError.jsx';
import Spinner from '../components/Spinner.jsx';
import { formatCents } from '../lib/money.js';

function isGone(err) {
  return err?.status === 404 || err?.type === 'RESOURCE_NOT_FOUND';
}

export default function CartPage() {
  const { lines, totalCents, atLineCap, setQuantity, remove, replace, clear } = useCart();
  const navigate = useNavigate();

  const [submitting, setSubmitting] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [declined, setDeclined] = useState(null);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState('');
  // In-progress quantity text per product id, so the field may be empty mid-edit.
  const [drafts, setDrafts] = useState({});
  const clearDraft = (id) =>
    setDrafts((d) => {
      if (!(id in d)) return d;
      const next = { ...d };
      delete next[id];
      return next;
    });
  // A ref, not state: two clicks in the same tick must still send one request.
  const inFlight = useRef(false);

  async function handlePay() {
    if (inFlight.current || lines.length === 0) return;
    inFlight.current = true;
    setSubmitting(true);
    setError(null);
    setDeclined(null);
    setNotice('');
    try {
      const order = await createPurchase(
        lines.map((l) => ({ productId: l.product.id, quantity: l.quantity }))
      );
      if (order.status === 'PAID') {
        clear();
        navigate(`/orders/${order.id}`, { state: { order } });
      } else if (order.status === 'FAILED') {
        // An expected outcome, not an error: the cart is kept, no stock was taken.
        setDeclined(order);
      } else {
        setError(new Error('Unexpected order status.'));
      }
    } catch (err) {
      setError(err);
    } finally {
      inFlight.current = false;
      setSubmitting(false);
    }
  }

  async function handleRefresh() {
    if (refreshing) return;
    setRefreshing(true);
    setNotice('');
    const next = {};
    const dropped = [];
    let failed = false;
    await Promise.all(
      lines.map(async (line) => {
        try {
          const p = await getProduct(line.product.id);
          const product = {
            id: p.id,
            name: p.name,
            sku: p.sku,
            priceCents: p.priceCents,
            stock: p.stock,
          };
          if (maxQuantity(product) < 1) {
            dropped.push(line.product.name);
            return;
          }
          next[product.id] = {
            product,
            quantity: Math.min(line.quantity, maxQuantity(product)),
          };
        } catch (err) {
          if (isGone(err)) {
            dropped.push(line.product.name);
          } else {
            failed = true;
            next[line.product.id] = line; // unknown: keep as is
          }
        }
      })
    );
    // Keep the original line order.
    const ordered = {};
    for (const l of lines) if (next[l.product.id]) ordered[l.product.id] = next[l.product.id];
    replace(ordered);
    setError(null);
    const parts = ['Prices and stock refreshed.'];
    if (dropped.length) parts.push(`Removed (unavailable): ${dropped.join(', ')}.`);
    if (failed) parts.push('Some items could not be refreshed.');
    setNotice(parts.join(' '));
    setRefreshing(false);
  }

  if (lines.length === 0 && !declined) {
    return (
      <section className="page">
        <h1>Cart</h1>
        {notice && <p role="status" aria-live="polite">{notice}</p>}
        <p>Your cart is empty.</p>
        <Link to="/products">Browse products</Link>
      </section>
    );
  }

  const fields = error?.type === 'VALIDATION_ERROR' ? error.fields : undefined;
  const canRefresh = error && (error.type === 'INSUFFICIENT_STOCK' || isGone(error));
  const busy = submitting || refreshing;

  return (
    <section className="page">
      <h1>Cart</h1>

      {declined && (
        <div className="result-banner result-banner--declined" role="status" aria-live="polite">
          <p>
            <strong>Payment declined.</strong> Order {declined.id} was not paid and no stock was
            reserved. Your cart is unchanged.
          </p>
          <button type="button" className="button" onClick={() => setDeclined(null)}>
            Try again
          </button>
        </div>
      )}

      {error && <ErrorBanner error={error} />}
      {canRefresh && (
        <p>
          <button type="button" className="button" onClick={handleRefresh} disabled={busy}>
            Refresh prices and stock
          </button>
        </p>
      )}
      {notice && <p role="status" aria-live="polite">{notice}</p>}
      {atLineCap && (
        <p role="status" aria-live="polite">
          Your cart has reached the limit of {MAX_LINES} different products.
        </p>
      )}

      <div className="table-wrap" role="region" aria-label="Cart items" tabIndex={0}>
        <table className="product-table">
          <thead>
            <tr>
              <th scope="col">Product</th>
              <th scope="col" className="num">Price</th>
              <th scope="col">Quantity</th>
              <th scope="col" className="num">Total</th>
              <th scope="col">Actions</th>
            </tr>
          </thead>
          <tbody>
            {lines.map((line, index) => {
              const { product, quantity } = line;
              const qtyPath = fieldPath('items', index, 'quantity');
              const idPath = fieldPath('items', index, 'productId');
              return (
                <tr key={product.id}>
                  <td>
                    {product.name} <small>({product.sku})</small>
                  </td>
                  <td className="num">{formatCents(product.priceCents)}</td>
                  <td>
                    <input
                      type="number"
                      className="qty-input"
                      aria-label={`Quantity for ${product.name}`}
                      min={1}
                      max={maxQuantity(product)}
                      value={product.id in drafts ? drafts[product.id] : quantity}
                      aria-invalid={fields && (fields[qtyPath] || fields[idPath]) ? 'true' : undefined}
                      aria-describedby={
                        [fields?.[qtyPath] && fieldErrorId(qtyPath), fields?.[idPath] && fieldErrorId(idPath)]
                          .filter(Boolean)
                          .join(' ') || undefined
                      }
                      disabled={busy}
                      onChange={(e) => {
                        const raw = e.target.value;
                        const n = parseInt(raw, 10);
                        if (Number.isNaN(n)) {
                          setDrafts((d) => ({ ...d, [product.id]: raw }));
                          return;
                        }
                        const max = maxQuantity(product);
                        setDrafts((d) => ({ ...d, [product.id]: n > max ? String(max) : raw }));
                        setQuantity(product.id, n);
                      }}
                      onBlur={() => clearDraft(product.id)}
                      onKeyDown={(e) => {
                        if (e.key === 'Enter') clearDraft(product.id);
                      }}
                    />
                    <FieldError fields={fields} name={qtyPath} />
                    <FieldError fields={fields} name={idPath} />
                  </td>
                  <td className="num">{formatCents(product.priceCents * quantity)}</td>
                  <td>
                    <button
                      type="button"
                      className="link-button link-button--danger"
                      onClick={() => remove(product.id)}
                      disabled={busy}
                      aria-label={`Remove ${product.name}`}
                    >
                      Remove
                    </button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      <p className="cart-total">
        Total: <strong>{formatCents(totalCents)}</strong>
      </p>
      <p>
        <button type="button" className="button" onClick={handlePay} disabled={busy}>
          {submitting ? 'Processing...' : 'Pay now'}
        </button>
      </p>
      {submitting && <Spinner />}
    </section>
  );
}
