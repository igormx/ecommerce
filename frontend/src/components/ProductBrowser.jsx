import { useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { listCategories } from '../api/categories.js';
import useProducts from '../hooks/useProducts.js';
import ErrorBanner from './ErrorBanner.jsx';
import Pagination from './Pagination.jsx';
import Spinner from './Spinner.jsx';

const DEBOUNCE_MS = 300;

/**
 * State lives in the URL query. `children` is a render prop
 * `({ items, reload }) => node`.
 */
export default function ProductBrowser({ title, actions = null, children }) {
  const [, setSearchParams] = useSearchParams();
  const { items, total, loading, settled, error, q, category, page, size, reload } = useProducts();

  const [input, setInput] = useState(q);
  const [categories, setCategories] = useState([]);

  // Latest committed q, readable from the debounce timer without re-arming it.
  const qRef = useRef(q);
  qRef.current = q;

  const totalPages = Math.max(1, Math.ceil(total / size));

  function update(changes) {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev);
      for (const [key, value] of Object.entries(changes)) {
        if (value === '' || value === null || value === undefined) next.delete(key);
        else next.set(key, String(value));
      }
      return next;
    });
  }

  function commitQuery(value) {
    const trimmed = value.trim();
    if (trimmed === qRef.current) return;
    update({ q: trimmed, page: null });
  }

  useEffect(() => {
    setInput(q);
  }, [q]);

  useEffect(() => {
    if (input.trim() === qRef.current) return undefined;
    const timer = setTimeout(() => commitQuery(input), DEBOUNCE_MS);
    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [input]);

  useEffect(() => {
    let cancelled = false;
    listCategories().then(
      (list) => {
        if (!cancelled) setCategories(Array.isArray(list) ? list : []);
      },
      () => {}
    );
    return () => {
      cancelled = true;
    };
  }, []);

  // Past the end (e.g. a stale link, or items removed): step back to the last page.
  useEffect(() => {
    if (settled && !error && page > totalPages) {
      setSearchParams(
        (prev) => {
          const next = new URLSearchParams(prev);
          if (totalPages <= 1) next.delete('page');
          else next.set('page', String(totalPages));
          return next;
        },
        { replace: true }
      );
    }
  }, [settled, error, page, totalPages, setSearchParams]);

  const categoryOptions =
    category && !categories.includes(category) ? [...categories, category] : categories;

  return (
    <section className="page">
      <div className="page-header">
        <h1>{title}</h1>
        {actions}
      </div>

      <form
        className="filters"
        role="search"
        onSubmit={(e) => {
          e.preventDefault();
          commitQuery(input);
        }}
      >
        <label className="filters__field">
          <span>Search</span>
          <input
            type="search"
            value={input}
            onChange={(e) => setInput(e.target.value)}
            placeholder="Search products"
          />
        </label>
        <label className="filters__field">
          <span>Category</span>
          <select value={category} onChange={(e) => update({ category: e.target.value, page: null })}>
            <option value="">All</option>
            {categoryOptions.map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </select>
        </label>
      </form>

      {error && <ErrorBanner error={error} />}
      <p className="sr-only" role="status" aria-live="polite">
        {!loading && !error ? `${total} ${total === 1 ? 'product' : 'products'} found` : ''}
      </p>
      {loading && <Spinner />}
      {!loading && !error && items.length === 0 && page <= totalPages && (
        <p>No products match.</p>
      )}
      {!error && items.length > 0 && children({ items, reload })}
      {!error && total > 0 && (
        <Pagination
          page={Math.min(page, totalPages)}
          totalPages={totalPages}
          onPageChange={(p) => update({ page: p <= 1 ? null : p })}
        />
      )}
    </section>
  );
}
