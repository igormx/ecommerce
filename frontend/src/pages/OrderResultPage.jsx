import { useEffect, useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import { getOrder } from '../api/purchases.js';
import ErrorBanner from '../components/ErrorBanner.jsx';
import Spinner from '../components/Spinner.jsx';
import { formatCents } from '../lib/money.js';

export default function OrderResultPage() {
  const { id } = useParams();
  const location = useLocation();
  // The checkout passes the order along so there is no flash of loading; a
  // reload (or a deep link) falls back to GET /orders/{id}.
  const passed = location.state?.order;
  const [order, setOrder] = useState(passed && String(passed.id) === id ? passed : null);
  const [error, setError] = useState(null);

  useEffect(() => {
    if (order && String(order.id) === id) return undefined;
    let cancelled = false;
    setOrder(null);
    setError(null);
    getOrder(id).then(
      (o) => {
        if (!cancelled) setOrder(o);
      },
      (err) => {
        if (!cancelled) setError(err);
      }
    );
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id]);

  if (error) {
    return (
      <section className="page">
        <h1>Order {id}</h1>
        <ErrorBanner
          error={error}
          message={error.status === 404 ? 'Order not found.' : undefined}
        />
        <Link to="/products">Back to products</Link>
      </section>
    );
  }

  if (!order) {
    return (
      <section className="page">
        <h1>Order {id}</h1>
        <Spinner />
      </section>
    );
  }

  const paid = order.status === 'PAID';
  const created = new Date(order.createdAt);

  return (
    <section className="page">
      <h1>Order {order.id}</h1>
      <p
        className={paid ? 'result-banner' : 'result-banner result-banner--declined'}
        role="status"
        aria-live="polite"
      >
        {paid ? 'Payment successful.' : 'Payment declined.'} Status: <strong>{order.status}</strong>
      </p>
      <p>
        Placed:{' '}
        <time dateTime={order.createdAt}>
          {Number.isNaN(created.getTime()) ? order.createdAt : created.toLocaleString()}
        </time>
      </p>

      <div className="table-wrap" role="region" aria-label="Order items" tabIndex={0}>
        <table className="product-table">
          <thead>
            <tr>
              <th scope="col">Product</th>
              <th scope="col">SKU</th>
              <th scope="col" className="num">Quantity</th>
              <th scope="col" className="num">Unit price</th>
            </tr>
          </thead>
          <tbody>
            {order.items.map((item) => (
              <tr key={item.productId}>
                <td>{item.name}</td>
                <td>{item.sku}</td>
                <td className="num">{item.quantity}</td>
                <td className="num">{formatCents(item.unitPriceCents)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <p className="cart-total">
        Total: <strong>{formatCents(order.totalCents)}</strong>
      </p>
      <Link to="/products">Continue shopping</Link>
    </section>
  );
}
