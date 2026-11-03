import { Link } from 'react-router-dom';
import { useCart } from '../cart/CartContext.jsx';
import { formatCents } from '../lib/money.js';

export default function ProductTable({ products, onDelete, deletingId }) {
  const { add } = useCart();

  return (
    <div className="table-wrap" role="region" aria-label="Products" tabIndex={0}>
      <table className="product-table">
        <thead>
          <tr>
            <th scope="col">Name</th>
            <th scope="col">SKU</th>
            <th scope="col">Category</th>
            <th scope="col" className="num">Price</th>
            <th scope="col" className="num">Stock</th>
            <th scope="col">Actions</th>
          </tr>
        </thead>
        <tbody>
          {products.map((p) => (
            <tr key={p.id}>
              <td>{p.name}</td>
              <td>{p.sku}</td>
              <td>{p.category}</td>
              <td className="num">{formatCents(p.priceCents)}</td>
              <td className="num">{p.stock}</td>
              <td>
                <Link to={`/products/${p.id}/edit`} aria-label={`Edit ${p.name}`}>
                  Edit
                </Link>{' '}
                <button
                  type="button"
                  className="link-button link-button--danger"
                  onClick={() => onDelete(p)}
                  disabled={deletingId === p.id}
                  aria-label={`Delete ${p.name}`}
                >
                  Delete
                </button>{' '}
                <button
                  type="button"
                  className="link-button"
                  onClick={() => add(p, 1)}
                  disabled={p.stock < 1}
                  aria-label={`Add ${p.name} to cart`}
                >
                  Add to cart
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
