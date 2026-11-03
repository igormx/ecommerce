import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, useNavigate } from 'react-router-dom';
import { ROUTER_FUTURE_FLAGS } from '../App.jsx';
import { CartProvider } from '../cart/CartContext.jsx';
import ProductsPage from './ProductsPage.jsx';

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

function renderPage(entry = '/products') {
  return render(
    <CartProvider>
      <MemoryRouter initialEntries={[entry]} future={ROUTER_FUTURE_FLAGS}>
        <ProductsPage />
      </MemoryRouter>
    </CartProvider>
  );
}

beforeEach(() => vi.unstubAllGlobals());
afterEach(() => vi.unstubAllGlobals());

describe('ProductsPage', () => {
  it('renders rows from the initial load', async () => {
    mockApi({ list: () => page([product(1), product(2)], 2) });
    renderPage();

    expect(await screen.findByText('Product 1')).toBeInTheDocument();
    expect(screen.getByText('Product 2')).toBeInTheDocument();
    expect(screen.getAllByText('$19.99')).toHaveLength(2);
    expect(screen.getByRole('link', { name: 'Edit Product 1' })).toHaveAttribute(
      'href',
      '/products/1/edit'
    );
  });

  it('searches with q on Enter and resets to page 1', async () => {
    const user = userEvent.setup();
    const fetchMock = mockApi({ list: () => page([product(1)], 60, 2) });
    renderPage('/products?page=2');
    await screen.findByText('Product 1');

    await user.type(screen.getByLabelText('Search'), 'shoe{Enter}');

    await waitFor(() => expect(productCalls(fetchMock, '/api/products/search')).toHaveLength(1));
    const call = productCalls(fetchMock, '/api/products/search')[0];
    expect(call.searchParams.get('q')).toBe('shoe');
    expect(call.searchParams.has('page') ? call.searchParams.get('page') : '1').toBe('1');
  });

  it('debounces typing into a single search', async () => {
    const user = userEvent.setup();
    const fetchMock = mockApi({ list: () => page([product(1)], 1) });
    renderPage();
    await screen.findByText('Product 1');

    await user.type(screen.getByLabelText('Search'), 'abc');

    await waitFor(() => expect(productCalls(fetchMock, '/api/products/search')).toHaveLength(1));
    expect(productCalls(fetchMock, '/api/products/search')[0].searchParams.get('q')).toBe('abc');
  });

  it('falls back to list when q is blank and no category is set', async () => {
    const fetchMock = mockApi({ list: () => page([product(1)], 1) });
    renderPage('/products?q=%20%20');

    await screen.findByText('Product 1');
    expect(productCalls(fetchMock, '/api/products/search')).toHaveLength(0);
    expect(productCalls(fetchMock, '/api/products')).toHaveLength(1);
  });

  it('calls search for a category alone', async () => {
    const user = userEvent.setup();
    const fetchMock = mockApi({ list: () => page([product(1)], 1) });
    renderPage();
    await screen.findByText('Product 1');
    await screen.findByRole('option', { name: 'Footwear' });

    await user.selectOptions(screen.getByLabelText('Category'), 'Footwear');

    await waitFor(() => expect(productCalls(fetchMock, '/api/products/search')).toHaveLength(1));
    const call = productCalls(fetchMock, '/api/products/search')[0];
    expect(call.searchParams.get('category')).toBe('Footwear');
    expect(call.searchParams.has('q')).toBe(false);
  });

  it('populates the category dropdown from /categories', async () => {
    mockApi({ list: () => page([product(1)], 1) });
    renderPage();

    const select = screen.getByLabelText('Category');
    await within(select).findByRole('option', { name: 'Books' });
    expect(within(select).getAllByRole('option').map((o) => o.textContent)).toEqual([
      'All',
      'Books',
      'Footwear',
    ]);
  });

  it('shows "page X of Y" from meta.total and disables Next on the last page', async () => {
    const user = userEvent.setup();
    const fetchMock = mockApi({
      list: (u) => page([product(1)], 45, Number(u.searchParams.get('page') ?? 1)),
    });
    renderPage();

    expect(await screen.findByText('Page 1 of 3')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled();

    await user.click(screen.getByRole('button', { name: 'Next' }));
    await user.click(await screen.findByRole('button', { name: 'Next' }));

    expect(await screen.findByText('Page 3 of 3')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled();
    expect(productCalls(fetchMock, '/api/products').at(-1).searchParams.get('page')).toBe('3');
  });

  it('steps back to the last page when the page is past the end', async () => {
    const fetchMock = mockApi({
      list: (u) =>
        Number(u.searchParams.get('page')) > 2
          ? page([], 30, 9)
          : page([product(1)], 30, Number(u.searchParams.get('page') ?? 1)),
    });
    renderPage('/products?page=9');

    expect(await screen.findByText('Page 2 of 2')).toBeInTheDocument();
    expect(productCalls(fetchMock, '/api/products').at(-1).searchParams.get('page')).toBe('2');
  });

  it('does not step back using a stale total after Back navigation', async () => {
    const fetchMock = mockApi({
      list: () => page([], 0),
      search: (u) =>
        u.searchParams.get('q') === 'old'
          ? page([product(1)], 50, Number(u.searchParams.get('page') ?? 1))
          : page([product(2)], 5, 1),
    });
    function BackButton() {
      const navigate = useNavigate();
      return <button onClick={() => navigate(-1)}>go back</button>;
    }
    render(
      <CartProvider>
        <MemoryRouter
          initialEntries={['/products?q=old&page=3', '/products?q=new']}
          initialIndex={1}
          future={ROUTER_FUTURE_FLAGS}
        >
          <BackButton />
          <ProductsPage />
        </MemoryRouter>
      </CartProvider>
    );

    expect(await screen.findByText('Page 1 of 1')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'go back' }));

    expect(await screen.findByText('Page 3 of 3')).toBeInTheDocument();
    const pages = productCalls(fetchMock, '/api/products/search').map((u) => u.searchParams.get('page'));
    expect(pages).toEqual(['1', '3']);
    expect(screen.getByRole('searchbox')).toHaveValue('old');
  });

  it('ignores a slow stale response that arrives after a newer one', async () => {
    let resolveSlow;
    const slow = new Promise((r) => {
      resolveSlow = r;
    });
    mockApi({
      list: () => slow,
      search: () => page([product(2, { name: 'Fresh result' })], 1),
    });
    renderPage();

    await screen.findByRole('option', { name: 'Books' });
    await userEvent.selectOptions(screen.getByLabelText('Category'), 'Books');
    expect(await screen.findByText('Fresh result')).toBeInTheDocument();

    resolveSlow(new Response(JSON.stringify({ statusCode: 200, data: [product(1, { name: 'Stale result' })], meta: { page: 1, size: 20, total: 1 } })));
    await new Promise((r) => setTimeout(r, 20));
    expect(screen.getByText('Fresh result')).toBeInTheDocument();
    expect(screen.queryByText('Stale result')).toBeNull();
  });

  it('shows the empty state', async () => {
    mockApi({ list: () => page([], 0) });
    renderPage();

    expect(await screen.findByText('No products match.')).toBeInTheDocument();
  });

  it('shows an ErrorBanner when the request fails', async () => {
    mockApi({
      list: () =>
        json({ statusCode: 500, error: { type: 'SERVER_ERROR', description: 'Boom happened' } }, 500),
    });
    renderPage();

    expect(await screen.findByRole('alert')).toHaveTextContent('Boom happened');
  });

  it('renders hostile names as plain text', async () => {
    mockApi({ list: () => page([product(1, { name: '<script>alert(1)</script>' })], 1) });
    const { container } = renderPage();

    expect(await screen.findByText('<script>alert(1)</script>')).toBeInTheDocument();
    expect(container.querySelector('script')).toBeNull();
  });
});

describe('ProductsPage add to cart', () => {
  it('adds to the cart and disables the button when stock is 0', async () => {
    window.localStorage.clear();
    const user = userEvent.setup();
    mockApi({ list: () => page([product(1), product(2, { stock: 0 })], 2) });
    renderPage();
    await screen.findByText('Product 1');

    expect(screen.getByRole('button', { name: 'Add Product 2 to cart' })).toBeDisabled();
    await user.click(screen.getByRole('button', { name: 'Add Product 1 to cart' }));
    await user.click(screen.getByRole('button', { name: 'Add Product 1 to cart' }));
    const stored = JSON.parse(window.localStorage.getItem('ecommerce.cart.v1'));
    expect(stored[1].quantity).toBe(2);
  });
});
