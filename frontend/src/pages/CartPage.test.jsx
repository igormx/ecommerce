import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { ROUTER_FUTURE_FLAGS } from '../App.jsx';
import { CartProvider, STORAGE_KEY } from '../cart/CartContext.jsx';
import Layout from '../components/Layout.jsx';
import CartPage from './CartPage.jsx';
import OrderResultPage from './OrderResultPage.jsx';

const prod = (id, overrides = {}) => ({
  id,
  name: `Product ${id}`,
  sku: `SKU-${id}`,
  priceCents: 1000,
  stock: 10,
  ...overrides,
});

function seed(lines) {
  const cart = {};
  for (const [product, quantity] of lines) cart[product.id] = { product, quantity };
  window.localStorage.setItem(STORAGE_KEY, JSON.stringify(cart));
}

const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status }));
const order = (status, overrides = {}) => ({
  id: 42,
  status,
  totalCents: 2000,
  createdAt: '2026-11-03T10:00:00+00:00',
  items: [{ productId: 1, sku: 'SKU-1', name: 'Product 1', quantity: 2, unitPriceCents: 1000 }],
  ...overrides,
});
const err = (status, type, description, fields) =>
  json({ statusCode: status, error: { type, description, fields } }, status);

function renderCart() {
  return render(
    <CartProvider>
      <MemoryRouter initialEntries={['/cart']} future={ROUTER_FUTURE_FLAGS}>
        <Layout>
          <Routes>
            <Route path="/cart" element={<CartPage />} />
            <Route path="/orders/:id" element={<OrderResultPage />} />
          </Routes>
        </Layout>
      </MemoryRouter>
    </CartProvider>
  );
}

const stored = () => JSON.parse(window.localStorage.getItem(STORAGE_KEY) || '{}');
const postCalls = (fetchMock) => fetchMock.mock.calls.filter(([url]) => url === '/api/purchases');

beforeEach(() => window.localStorage.clear());
afterEach(() => vi.unstubAllGlobals());

describe('CartPage', () => {
  describe('quantity input editing', () => {
    const qty = () => screen.getByLabelText('Quantity for Product 1');

    it('lets the user clear the field and type a new quantity', async () => {
      seed([[prod(1), 1]]);
      renderCart();
      const user = userEvent.setup();
      await user.clear(qty());
      expect(qty()).toHaveValue(null);
      await user.type(qty(), '5');
      expect(qty()).toHaveValue(5);
      expect(stored()[1].quantity).toBe(5);
    });

    it('restores the previous quantity when blurred empty', async () => {
      seed([[prod(1), 3]]);
      renderCart();
      const user = userEvent.setup();
      await user.clear(qty());
      await user.tab();
      expect(qty()).toHaveValue(3);
      expect(stored()[1].quantity).toBe(3);
    });

    it('clamps 0 up to 1 and over-stock down to stock', async () => {
      seed([[prod(1), 4]]);
      renderCart();
      const user = userEvent.setup();
      await user.clear(qty());
      await user.type(qty(), '0');
      await user.tab();
      expect(qty()).toHaveValue(1);
      expect(stored()[1].quantity).toBe(1);
      await user.clear(qty());
      await user.type(qty(), '99');
      await user.tab();
      expect(qty()).toHaveValue(10);
      expect(stored()[1].quantity).toBe(10);
    });
  });

  it('shows the empty state', () => {
    renderCart();
    expect(screen.getByText('Your cart is empty.')).toBeInTheDocument();
  });

  it('shows lines, integer-cent totals, and edits quantity and removal', async () => {
    seed([[prod(1, { priceCents: 1999 }), 3], [prod(2, { priceCents: 5 }), 1]]);
    const user = userEvent.setup();
    renderCart();

    expect(screen.getByText('$59.97')).toBeInTheDocument(); // line total 3 x 19.99
    expect(screen.getByText(/Total:/)).toHaveTextContent('$60.02');
    expect(screen.getByRole('link', { name: 'Cart (4)' })).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Quantity for Product 1'), { target: { value: '99' } });
    expect(screen.getByLabelText('Quantity for Product 1')).toHaveValue(10); // clamped to stock

    await user.click(screen.getByRole('button', { name: 'Remove Product 2' }));
    expect(screen.queryByText('Product 2')).not.toBeInTheDocument();
  });

  it('shows a message at the 50-line cap', () => {
    const lines = [];
    for (let i = 1; i <= 50; i++) lines.push([prod(i), 1]);
    seed(lines);
    renderCart();
    expect(screen.getByText(/limit of 50 different products/)).toBeInTheDocument();
  });

  it('PAID clears the cart and navigates to the order', async () => {
    seed([[prod(1), 2]]);
    const fetchMock = vi.fn((url) =>
      url === '/api/purchases'
        ? json({ statusCode: 201, data: order('PAID') }, 201)
        : json({ statusCode: 200, data: order('PAID') })
    );
    vi.stubGlobal('fetch', fetchMock);
    const user = userEvent.setup();
    renderCart();

    await user.click(screen.getByRole('button', { name: 'Pay now' }));

    expect(await screen.findByRole('heading', { name: 'Order 42' })).toBeInTheDocument();
    expect(screen.getByText('PAID')).toBeInTheDocument();
    expect(screen.getByText('Product 1')).toBeInTheDocument();
    expect(stored()).toEqual({});
    expect(screen.getByRole('link', { name: 'Cart (0)' })).toBeInTheDocument();
    expect(JSON.parse(postCalls(fetchMock)[0][1].body)).toEqual({
      items: [{ productId: 1, quantity: 2 }],
    });
  });

  it('FAILED keeps the cart and shows the declined state with Try again', async () => {
    seed([[prod(1), 2]]);
    vi.stubGlobal(
      'fetch',
      vi.fn(() => json({ statusCode: 201, data: order('FAILED') }, 201))
    );
    const user = userEvent.setup();
    renderCart();

    await user.click(screen.getByRole('button', { name: 'Pay now' }));

    expect(await screen.findByText(/Payment declined/)).toBeInTheDocument();
    expect(screen.queryByText(/Something went wrong/)).not.toBeInTheDocument();
    expect(Object.keys(stored())).toEqual(['1']);
    await user.click(screen.getByRole('button', { name: 'Try again' }));
    expect(screen.queryByText(/Payment declined/)).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Pay now' })).toBeEnabled();
  });

  it('insufficient stock keeps the cart, shows the message and refreshes via getProduct', async () => {
    seed([[prod(1), 5], [prod(2), 1]]);
    const fetchMock = vi.fn((url) => {
      if (url === '/api/purchases') return err(409, 'INSUFFICIENT_STOCK', 'Not enough stock for Product 1');
      if (url === '/api/products/1') return json({ statusCode: 200, data: prod(1, { stock: 2, priceCents: 1500 }) });
      return err(404, 'RESOURCE_NOT_FOUND', 'gone');
    });
    vi.stubGlobal('fetch', fetchMock);
    const user = userEvent.setup();
    renderCart();

    await user.click(screen.getByRole('button', { name: 'Pay now' }));
    expect(await screen.findByText('Not enough stock for Product 1')).toBeInTheDocument();
    expect(Object.keys(stored())).toHaveLength(2);

    await user.click(screen.getByRole('button', { name: 'Refresh prices and stock' }));
    await waitFor(() => expect(screen.queryByText('Product 2')).not.toBeInTheDocument());
    expect(screen.getByLabelText('Quantity for Product 1')).toHaveValue(2);
    expect(screen.getByText(/Removed \(unavailable\): Product 2/)).toBeInTheDocument();
    expect(screen.queryByText('Not enough stock for Product 1')).not.toBeInTheDocument();
    expect(stored()[1].product.priceCents).toBe(1500);
  });

  it('a 404 keeps the cart and offers a refresh', async () => {
    seed([[prod(1), 1]]);
    vi.stubGlobal('fetch', vi.fn(() => err(404, 'RESOURCE_NOT_FOUND', 'Product 1 not found')));
    const user = userEvent.setup();
    renderCart();

    await user.click(screen.getByRole('button', { name: 'Pay now' }));
    expect(await screen.findByText('Product 1 not found')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Refresh prices and stock' })).toBeInTheDocument();
    expect(Object.keys(stored())).toEqual(['1']);
  });

  it('maps items.N.quantity validation errors to the right line', async () => {
    seed([[prod(1), 1], [prod(2), 1]]);
    vi.stubGlobal(
      'fetch',
      vi.fn(() =>
        err(400, 'VALIDATION_ERROR', 'The request is invalid.', {
          'items.1.quantity': '0 must be between 1 and 1000',
        })
      )
    );
    const user = userEvent.setup();
    renderCart();

    await user.click(screen.getByRole('button', { name: 'Pay now' }));

    const row2 = screen.getByText('Product 2').closest('tr');
    expect(await within(row2).findByText('0 must be between 1 and 1000')).toBeInTheDocument();
    expect(within(screen.getByText('Product 1').closest('tr')).queryByRole('alert')).toBeNull();
  });

  it('disables Pay now in flight and sends one request on double click', async () => {
    seed([[prod(1), 1]]);
    let resolve;
    const fetchMock = vi.fn(
      () =>
        new Promise((r) => {
          resolve = () => r(new Response(JSON.stringify({ statusCode: 201, data: order('FAILED') }), { status: 201 }));
        })
    );
    vi.stubGlobal('fetch', fetchMock);
    const user = userEvent.setup();
    renderCart();

    await user.dblClick(screen.getByRole('button', { name: 'Pay now' }));

    expect(screen.getByRole('button', { name: 'Processing...' })).toBeDisabled();
    expect(postCalls(fetchMock)).toHaveLength(1);
    resolve();
    expect(await screen.findByText(/Payment declined/)).toBeInTheDocument();
    expect(postCalls(fetchMock)).toHaveLength(1);
  });
});

describe('OrderResultPage', () => {
  it('loads GET /orders/{id} on a direct visit', async () => {
    const fetchMock = vi.fn(() => json({ statusCode: 200, data: order('FAILED') }));
    vi.stubGlobal('fetch', fetchMock);
    render(
      <CartProvider>
        <MemoryRouter initialEntries={['/orders/42']} future={ROUTER_FUTURE_FLAGS}>
          <Routes>
            <Route path="/orders/:id" element={<OrderResultPage />} />
          </Routes>
        </MemoryRouter>
      </CartProvider>
    );
    expect(await screen.findByText('FAILED')).toBeInTheDocument();
    expect(fetchMock.mock.calls[0][0]).toBe('/api/orders/42');
    expect(screen.getByText('$20.00', { selector: 'strong' })).toBeInTheDocument();
    expect(screen.getByText('Product 1')).toBeInTheDocument();
  });

  it('shows not found for an unknown order', async () => {
    vi.stubGlobal('fetch', vi.fn(() => err(404, 'RESOURCE_NOT_FOUND', 'x')));
    render(
      <CartProvider>
        <MemoryRouter initialEntries={['/orders/9']} future={ROUTER_FUTURE_FLAGS}>
          <Routes>
            <Route path="/orders/:id" element={<OrderResultPage />} />
          </Routes>
        </MemoryRouter>
      </CartProvider>
    );
    expect(await screen.findByText('Order not found.')).toBeInTheDocument();
  });
});
