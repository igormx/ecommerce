import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { CartProvider } from './cart/CartContext.jsx';
import Layout from './components/Layout.jsx';
import { AppRoutes, ROUTER_FUTURE_FLAGS } from './App.jsx';

function renderAt(path) {
  return render(
    <CartProvider>
      <MemoryRouter initialEntries={[path]} future={ROUTER_FUTURE_FLAGS}>
        <Layout>
          <AppRoutes />
        </Layout>
      </MemoryRouter>
    </CartProvider>
  );
}

describe('routing', () => {
  // ProductsPage fetches on mount; keep these smoke tests off the network.
  beforeEach(() => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() =>
        Promise.resolve(
          new Response(JSON.stringify({ statusCode: 200, data: [], meta: { page: 1, size: 20, total: 0 } }))
        )
      )
    );
  });
  afterEach(() => vi.unstubAllGlobals());

  it('renders NotFoundPage for an unknown path', () => {
    renderAt('/this-page-does-not-exist');

    expect(screen.getByRole('heading', { name: /page not found/i })).toBeInTheDocument();
  });

  it('redirects / to /products', async () => {
    renderAt('/');

    expect(screen.getByRole('heading', { name: /^products$/i })).toBeInTheDocument();
    expect(await screen.findByText('No products match.')).toBeInTheDocument();
  });

  it('renders the layout navigation', async () => {
    renderAt('/products');
    await screen.findByText('No products match.');

    expect(screen.getByRole('link', { name: /^products$/i })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /new product/i })).toBeNull();
    expect(screen.getByRole('link', { name: /cart/i })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Admin' })).toHaveAttribute('href', '/admin/products');
    expect(screen.queryByText('Admin section')).toBeNull();
  });

  it('marks the admin context and links back to the storefront', async () => {
    renderAt('/admin/products');
    await screen.findByText('No products match.');

    expect(screen.getByText('Admin section')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Admin' })).toHaveAttribute('aria-current', 'page');
    expect(screen.getByRole('link', { name: 'Back to storefront' })).toHaveAttribute('href', '/products');
    expect(screen.getByRole('link', { name: /^products$/i })).toHaveAttribute('href', '/products');
    expect(screen.getByRole('link', { name: /new product/i })).toBeInTheDocument();
  });
});
