import { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { listProducts, searchProducts } from '../api/products.js';

export const PAGE_SIZE = 20;

export function parsePage(value) {
  const n = Number.parseInt(value, 10);
  return Number.isFinite(n) && n >= 1 ? n : 1;
}

/**
 * Search is used when trimmed `q` or `category` is set.
 * Superseded responses are ignored.
 */
export default function useProducts() {
  const [params] = useSearchParams();
  const q = (params.get('q') ?? '').trim();
  const category = (params.get('category') ?? '').trim();
  const page = parsePage(params.get('page'));

  const [reloadKey, setReloadKey] = useState(0);
  const [state, setState] = useState({ items: [], total: 0, loading: true, error: null, key: null });
  const key = JSON.stringify([q, category, page]);

  useEffect(() => {
    let stale = false;
    const requestKey = JSON.stringify([q, category, page]);
    setState((s) => ({ ...s, loading: true, error: null }));

    const call =
      q || category
        ? searchProducts({ q, category, page, size: PAGE_SIZE })
        : listProducts({ page, size: PAGE_SIZE });

    call.then(
      ({ items, meta }) => {
        if (stale) return;
        setState({ items, total: meta?.total ?? items.length, loading: false, error: null, key: requestKey });
      },
      (error) => {
        if (stale) return;
        setState({ items: [], total: 0, loading: false, error, key: requestKey });
      }
    );

    return () => {
      stale = true;
    };
  }, [q, category, page, reloadKey]);

  const reload = useCallback(() => setReloadKey((k) => k + 1), []);

  // True only when the loaded data was fetched for the params now in the URL.
  const settled = !state.loading && state.key === key;

  return { ...state, settled, q, category, page, size: PAGE_SIZE, reload };
}
