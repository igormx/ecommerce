import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes, useNavigate } from 'react-router-dom';
import { AppRoutes, ROUTER_FUTURE_FLAGS } from '../App.jsx';
import ProductFormPage from './ProductFormPage.jsx';
import { CartProvider } from '../cart/CartContext.jsx';
import ProductsPage from './ProductsPage.jsx';

function json(body, status = 200) {
  return Promise.resolve(new Response(JSON.stringify(body), { status }));
}

const EXISTING = {
  id: 7,
  sku: 'AB-1',
  name: 'Widget',
  description: 'Nice',
  category: 'Tools',
  priceCents: 1999,
  stock: 3,
  weightKg: 1.5,
};

// `onWrite(method, url, body)` returns the response promise for POST/PUT/DELETE.
function mockApi({ onWrite, product = EXISTING, list } = {}) {
  const fetchMock = vi.fn((url, init = {}) => {
    const method = init.method ?? 'GET';
    const u = new URL(url, 'http://x');
    if (u.pathname === '/api/categories') return json({ statusCode: 200, data: ['Books', 'Tools'] });
    if (method === 'GET' && u.pathname === '/api/products') {
      return list ? list() : json({ statusCode: 200, data: [], meta: { page: 1, size: 20, total: 0 } });
    }
    if (method === 'GET' && /^\/api\/products\/\d+$/.test(u.pathname)) {
      return product
        ? json({ statusCode: 200, data: product })
        : json({ statusCode: 404, error: { type: 'RESOURCE_NOT_FOUND', description: 'Product not found' } }, 404);
    }
    return onWrite(method, u.pathname, init.body ? JSON.parse(init.body) : undefined);
  });
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

const writes = (fetchMock) =>
  fetchMock.mock.calls.filter(([, init]) => init?.method && init.method !== 'GET');

function renderForm(entry, mode) {
  return render(
    <MemoryRouter initialEntries={[entry]} future={ROUTER_FUTURE_FLAGS}>
      <Routes>
        <Route path="/products" element={<div>list page</div>} />
        <Route path="/products/new" element={<ProductFormPage mode="create" />} />
        <Route path="/products/:id/edit" element={<ProductFormPage mode={mode} />} />
      </Routes>
    </MemoryRouter>
  );
}

async function fillCreate(user) {
  await user.type(screen.getByLabelText('SKU'), ' NEW-1 ');
  await user.type(screen.getByLabelText('Name'), 'New thing');
  await user.type(screen.getByLabelText('Price (USD)'), '19.99');
  await user.type(screen.getByLabelText('Stock'), '4');
}

beforeEach(() => vi.unstubAllGlobals());
afterEach(() => vi.unstubAllGlobals());

describe('ProductFormPage', () => {
  it('create sends cents and null optionals, then navigates to the list', async () => {
    const user = userEvent.setup();
    const fetchMock = mockApi({ onWrite: () => json({ statusCode: 201, data: { id: 1 } }, 201) });
    renderForm('/products/new', 'create');
    await fillCreate(user);
    await user.click(screen.getByRole('button', { name: 'Create product' }));

    expect(await screen.findByText('list page')).toBeInTheDocument();
    const [[url, init]] = writes(fetchMock);
    expect(url).toBe('/api/products');
    expect(init.method).toBe('POST');
    expect(JSON.parse(init.body)).toEqual({
      sku: 'NEW-1',
      name: 'New thing',
      description: null,
      category: null,
      priceCents: 1999,
      stock: 4,
      weightKg: null,
    });
  });

  it('validates client-side without calling the API', async () => {
    const user = userEvent.setup();
    const fetchMock = mockApi({ onWrite: () => json({}, 500) });
    renderForm('/products/new', 'create');
    await user.type(screen.getByLabelText('Price (USD)'), '1.999');
    await user.click(screen.getByRole('button', { name: 'Create product' }));

    expect(await screen.findByText(/no more than two decimals/)).toBeInTheDocument();
    expect(screen.getByText(/SKU must be/)).toBeInTheDocument();
    expect(writes(fetchMock)).toHaveLength(0);
  });

  it('edit pre-fills and sends PUT', async () => {
    const user = userEvent.setup();
    const fetchMock = mockApi({ onWrite: () => json({ statusCode: 200, data: EXISTING }) });
    renderForm('/products/7/edit', 'edit');

    expect(await screen.findByDisplayValue('Widget')).toBeInTheDocument();
    expect(screen.getByLabelText('Price (USD)')).toHaveValue('19.99');
    expect(screen.getByLabelText('Weight (kg, optional)')).toHaveValue('1.5');

    await user.clear(screen.getByLabelText('Name'));
    await user.type(screen.getByLabelText('Name'), 'Gadget');
    await user.clear(screen.getByLabelText('Category'));
    await user.click(screen.getByRole('button', { name: 'Save changes' }));

    expect(await screen.findByText('list page')).toBeInTheDocument();
    const [[url, init]] = writes(fetchMock);
    expect(url).toBe('/api/products/7');
    expect(init.method).toBe('PUT');
    expect(JSON.parse(init.body)).toEqual({
      sku: 'AB-1',
      name: 'Gadget',
      description: 'Nice',
      category: null,
      priceCents: 1999,
      stock: 3,
      weightKg: 1.5,
    });
  });

  it('shows server field errors next to the inputs', async () => {
    const user = userEvent.setup();
    mockApi({
      onWrite: () =>
        json(
          {
            statusCode: 400,
            error: {
              type: 'VALIDATION_ERROR',
              description: 'Invalid',
              fields: { priceCents: 'too big', name: 'bad name' },
            },
          },
          400
        ),
    });
    renderForm('/products/new', 'create');
    await fillCreate(user);
    await user.click(screen.getByRole('button', { name: 'Create product' }));

    expect(await screen.findByText('too big')).toBeInTheDocument();
    expect(screen.getByText('bad name')).toBeInTheDocument();
    expect(screen.getByLabelText('Price (USD)')).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByRole('button', { name: 'Create product' })).toBeEnabled();
  });

  it('shows a duplicate SKU error on the sku field', async () => {
    const user = userEvent.setup();
    mockApi({
      onWrite: () =>
        json({ statusCode: 409, error: { type: 'CONFLICT', description: 'Duplicate' } }, 409),
    });
    renderForm('/products/new', 'create');
    await fillCreate(user);
    await user.click(screen.getByRole('button', { name: 'Create product' }));

    expect(await screen.findByText('SKU already exists')).toBeInTheDocument();
    expect(screen.getByLabelText('SKU')).toHaveAttribute('aria-invalid', 'true');
  });

  it('disables submit while pending and sends one request', async () => {
    const user = userEvent.setup();
    let resolve;
    const fetchMock = mockApi({
      onWrite: () =>
        new Promise((r) => {
          resolve = () => r(new Response(JSON.stringify({ statusCode: 201, data: {} }), { status: 201 }));
        }),
    });
    renderForm('/products/new', 'create');
    await fillCreate(user);
    await user.click(screen.getByRole('button', { name: 'Create product' }));

    const button = await screen.findByRole('button', { name: 'Saving…' });
    expect(button).toBeDisabled();
    await user.click(button);
    expect(writes(fetchMock)).toHaveLength(1);

    resolve();
    expect(await screen.findByText('list page')).toBeInTheDocument();
  });

  it('shows not-found for an unknown id on edit', async () => {
    mockApi({ product: null, onWrite: () => json({}, 500) });
    renderForm('/products/999/edit', 'edit');
    expect(await screen.findByText(/Product not found/)).toBeInTheDocument();
  });
});

describe('ProductsPage delete', () => {
  const row = { id: 1, sku: 'S-1', name: 'Doomed', category: 'Books', priceCents: 100, stock: 1 };

  function renderList() {
    return render(
      <CartProvider>
        <MemoryRouter initialEntries={['/products']} future={ROUTER_FUTURE_FLAGS}>
          <ProductsPage />
        </MemoryRouter>
      </CartProvider>
    );
  }

  it('confirms, deletes and refetches the list', async () => {
    const user = userEvent.setup();
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    let deleted = false;
    const fetchMock = mockApi({
      list: () =>
        json({
          statusCode: 200,
          data: deleted ? [] : [row],
          meta: { page: 1, size: 20, total: deleted ? 0 : 1 },
        }),
      onWrite: () => {
        deleted = true;
        return Promise.resolve(new Response(null, { status: 204 }));
      },
    });
    renderList();
    await user.click(await screen.findByRole('button', { name: 'Delete Doomed' }));

    await waitFor(() => expect(screen.queryByText('Doomed')).not.toBeInTheDocument());
    expect(window.confirm).toHaveBeenCalled();
    expect(writes(fetchMock)[0][0]).toBe('/api/products/1');
    expect(writes(fetchMock)[0][1].method).toBe('DELETE');
  });

  it('does nothing when the confirm is cancelled', async () => {
    const user = userEvent.setup();
    vi.spyOn(window, 'confirm').mockReturnValue(false);
    const fetchMock = mockApi({
      list: () => json({ statusCode: 200, data: [row], meta: { page: 1, size: 20, total: 1 } }),
      onWrite: () => json({}, 500),
    });
    renderList();
    await user.click(await screen.findByRole('button', { name: 'Delete Doomed' }));
    expect(writes(fetchMock)).toHaveLength(0);
  });

  it('treats a 404 on delete as already gone and refetches', async () => {
    const user = userEvent.setup();
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    let calls = 0;
    mockApi({
      list: () => {
        calls += 1;
        return json({ statusCode: 200, data: calls > 1 ? [] : [row], meta: { page: 1, size: 20, total: calls > 1 ? 0 : 1 } });
      },
      onWrite: () =>
        json({ statusCode: 404, error: { type: 'RESOURCE_NOT_FOUND', description: 'gone' } }, 404),
    });
    renderList();
    await user.click(await screen.findByRole('button', { name: 'Delete Doomed' }));

    await waitFor(() => expect(screen.queryByText('Doomed')).not.toBeInTheDocument());
    expect(screen.queryByText('gone')).not.toBeInTheDocument();
  });

  it('resets the form when moving between edit and new, and between products', async () => {
    const user = userEvent.setup();
    vi.stubGlobal(
      'fetch',
      vi.fn((url) => {
        const u = new URL(url, 'http://x');
        if (u.pathname === '/api/categories') return json({ statusCode: 200, data: [] });
        const m = u.pathname.match(/^\/api\/products\/(\d+)$/);
        if (m) {
          return json({ statusCode: 200, data: { ...EXISTING, id: Number(m[1]), sku: `SKU-${m[1]}`, name: `Name ${m[1]}` } });
        }
        return json({ statusCode: 200, data: [], meta: { page: 1, size: 20, total: 0 } });
      })
    );
    function Nav() {
      const navigate = useNavigate();
      return (
        <>
          <button onClick={() => navigate('/products/new')}>to new</button>
          <button onClick={() => navigate('/products/4/edit')}>to edit 4</button>
        </>
      );
    }
    render(
      <CartProvider>
        <MemoryRouter initialEntries={['/products/3/edit']} future={ROUTER_FUTURE_FLAGS}>
          <Nav />
          <AppRoutes />
        </MemoryRouter>
      </CartProvider>
    );

    expect(await screen.findByDisplayValue('Name 3')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'to edit 4' }));
    expect(await screen.findByDisplayValue('Name 4')).toBeInTheDocument();
    expect(screen.queryByDisplayValue('Name 3')).toBeNull();

    await user.click(screen.getByRole('button', { name: 'to new' }));
    await waitFor(() => expect(screen.getByLabelText('Name')).toHaveValue(''));
    expect(screen.getByLabelText('Price (USD)')).toHaveValue('');
    expect(screen.getByLabelText('Category')).toHaveValue('');
  });
});
