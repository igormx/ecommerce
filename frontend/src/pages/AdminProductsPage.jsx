import { useState } from 'react';
import { Link } from 'react-router-dom';
import { deleteProduct } from '../api/products.js';
import ErrorBanner from '../components/ErrorBanner.jsx';
import ProductBrowser from '../components/ProductBrowser.jsx';
import ProductTable from '../components/ProductTable.jsx';

function AdminRows({ items, reload }) {
  const [deletingId, setDeletingId] = useState(null);
  const [deleteError, setDeleteError] = useState(null);

  async function handleDelete(product) {
    if (!window.confirm(`Delete "${product.name}"?`)) return;
    setDeleteError(null);
    setDeletingId(product.id);
    try {
      await deleteProduct(product.id);
    } catch (err) {
      // 404 means it is already gone, which is the outcome the user wanted.
      if (err?.status !== 404 && err?.type !== 'RESOURCE_NOT_FOUND') {
        setDeleteError(err);
        setDeletingId(null);
        return;
      }
    }
    setDeletingId(null);
    reload();
  }

  return (
    <>
      {deleteError && <ErrorBanner error={deleteError} />}
      <ProductTable products={items} mode="admin" onDelete={handleDelete} deletingId={deletingId} />
    </>
  );
}

// There is no auth: this split is a UX separation only.
export default function AdminProductsPage() {
  return (
    <ProductBrowser
      title="Manage products"
      actions={
        <Link to="/admin/products/new" className="button">
          New product
        </Link>
      }
    >
      {({ items, reload }) => <AdminRows items={items} reload={reload} />}
    </ProductBrowser>
  );
}
