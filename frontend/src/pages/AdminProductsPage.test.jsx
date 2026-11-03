import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { ROUTER_FUTURE_FLAGS } from '../App.jsx';
import { CartProvider } from '../cart/CartContext.jsx';
import AdminProductsPage from './AdminProductsPage.jsx';

const CATEGORIES = ['Books', 'Footwear'];

function product(id, overrides = {}) {
  return {
    id,
    sku: `SKU-${id}`,
    name: `Product ${id}`,
    category: 'Books',
    priceCents: 1999,
    stock: 5,
    ...overrides,
  };
}

function json(body, status = 200) {
  return Promise.resolve(new Response(JSON.stringify(body), { status }));
}

// Routes mocked fetch calls by path; `handlers.list` / `handlers.search` get the URL.
function mockApi({ list, search } = {}) {
  const fetchMock = vi.fn((url) => {
    const u = new URL(url, 'http://x');
    if (u.pathname === '/api/categories') return json({ statusCode: 200, data: CATEGORIES });
    if (u.pathname === '/api/products/search') return (search ?? list)(u);
    if (u.pathname === '/api/products') return list(u);
    return json({ statusCode: 404, error: { type: 'RESOURCE_NOT_FOUND', description: 'nope' } }, 404);
  });
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

const page = (items, total, p = 1, size = 20) =>
  json({ statusCode: 200, data: items, meta: { page: p, size, total } });

const productCalls = (fetchMock, path) =>
  fetchMock.mock.calls
    .map(([url]) => new URL(url, 'http://x'))
    .filter((u) => u.pathname === path);

function renderPage(entry = '/admin/products') {
  return render(
    <CartProvider>
      <MemoryRouter initialEntries={[entry]} future={ROUTER_FUTURE_FLAGS}>
        <AdminProductsPage />
      </MemoryRouter>
    </CartProvider>
  );
}

beforeEach(() => vi.unstubAllGlobals());
afterEach(() => vi.unstubAllGlobals());

function Where() {
  const loc = useLocation();
  return <output data-testid="where">{loc.pathname + loc.search}</output>;
}

describe('AdminProductsPage', () => {
  it('shows sku, Edit, Delete and New product, but no Add to cart', async () => {
    mockApi({ list: () => page([product(1), product(2, { stock: 0 })], 2) });
    renderPage();

    expect(await screen.findByText('Product 1')).toBeInTheDocument();
    expect(screen.getByRole('columnheader', { name: 'SKU' })).toBeInTheDocument();
    expect(screen.getByText('SKU-1')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Edit Product 1' })).toHaveAttribute(
      'href',
      '/admin/products/1/edit'
    );
    expect(screen.getByRole('button', { name: 'Delete Product 1' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'New product' })).toHaveAttribute(
      'href',
      '/admin/products/new'
    );
    expect(screen.queryByRole('button', { name: /add .* to cart/i })).toBeNull();
  });

  it('announces the result count', async () => {
    mockApi({ list: () => page([product(1)], 1) });
    renderPage();
    expect(await screen.findByText('1 product found')).toHaveAttribute('aria-live', 'polite');
  });

  it('searches and pages within /admin/products', async () => {
    const user = userEvent.setup();
    mockApi({ list: () => page([product(1)], 60), search: () => page([product(1)], 60) });
    render(
      <CartProvider>
        <MemoryRouter initialEntries={['/admin/products']} future={ROUTER_FUTURE_FLAGS}>
          <AdminProductsPage />
          <Where />
        </MemoryRouter>
      </CartProvider>
    );
    await screen.findByText('Product 1');

    await user.click(screen.getByRole('button', { name: 'Next' }));
    expect(screen.getByTestId('where')).toHaveTextContent('/admin/products?page=2');

    await user.type(screen.getByLabelText('Search'), 'shoe{Enter}');
    await waitFor(() =>
      expect(screen.getByTestId('where')).toHaveTextContent('/admin/products?q=shoe')
    );
  });
});
