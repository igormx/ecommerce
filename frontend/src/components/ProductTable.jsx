import { Link } from 'react-router-dom';
import { useCart } from '../cart/CartContext.jsx';
import { formatCents } from '../lib/money.js';

/**
 * mode "storefront": name, category, price, stock, Add to cart.
 * mode "admin": sku, name, category, price, stock, Edit / Delete.
 */
export default function ProductTable({ products, mode = 'storefront', onDelete, deletingId }) {
  const { add } = useCart();
  const admin = mode === 'admin';

  return (
    <div className="table-wrap" role="region" aria-label="Products" tabIndex={0}>
      <table className="product-table">
        <thead>
          <tr>
            {admin && <th scope="col">SKU</th>}
            <th scope="col">Name</th>
            <th scope="col">Category</th>
            <th scope="col" className="num">Price</th>
            <th scope="col" className="num">Stock</th>
            <th scope="col">Actions</th>
          </tr>
        </thead>
        <tbody>
          {products.map((p) => (
            <tr key={p.id}>
              {admin && <td>{p.sku}</td>}
              <td>{p.name}</td>
              <td>{p.category}</td>
              <td className="num">{formatCents(p.priceCents)}</td>
              <td className="num">{p.stock}</td>
              <td>
                {admin ? (
                  <>
                    <Link to={`/admin/products/${p.id}/edit`} aria-label={`Edit ${p.name}`}>
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
                    </button>
                  </>
                ) : (
                  <button
                    type="button"
                    className="link-button"
                    onClick={() => add(p, 1)}
                    disabled={p.stock < 1}
                    aria-label={`Add ${p.name} to cart`}
                  >
                    Add to cart
                  </button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
