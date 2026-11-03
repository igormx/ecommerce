import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {
  CartProvider,
  MAX_LINES,
  STORAGE_KEY,
  cartReducer,
  loadCart,
  useCart,
} from './CartContext.jsx';

const prod = (id, overrides = {}) => ({
  id,
  name: `P${id}`,
  sku: `S${id}`,
  priceCents: 100,
  stock: 10,
  ...overrides,
});

const add = (state, product, quantity = 1) => cartReducer(state, { type: 'add', product, quantity });

describe('cartReducer', () => {
  it('adds a product and merges duplicate adds into one line', () => {
    let s = add({}, prod(1));
    s = add(s, prod(1), 2);
    expect(Object.keys(s)).toEqual(['1']);
    expect(s[1].quantity).toBe(3);
  });

  it('stores only the product snapshot fields', () => {
    const s = add({}, { ...prod(1), description: 'x', weightKg: 1 });
    expect(Object.keys(s[1].product).sort()).toEqual(['id', 'name', 'priceCents', 'sku', 'stock']);
  });

  it('clamps to min(stock, 1000) on add and setQuantity', () => {
    let s = add({}, prod(1, { stock: 3 }), 99);
    expect(s[1].quantity).toBe(3);
    s = cartReducer(s, { type: 'setQuantity', productId: 1, quantity: 0 });
    expect(s[1].quantity).toBe(1);
    s = cartReducer(s, { type: 'setQuantity', productId: 1, quantity: 500 });
    expect(s[1].quantity).toBe(3);

    s = add({}, prod(2, { stock: 5000 }), 5000);
    expect(s[2].quantity).toBe(1000);
  });

  it('ignores out-of-stock products and adds beyond the line cap', () => {
    expect(add({}, prod(1, { stock: 0 }))).toEqual({});
    let s = {};
    for (let i = 1; i <= MAX_LINES; i++) s = add(s, prod(i));
    expect(Object.keys(s)).toHaveLength(MAX_LINES);
    expect(add(s, prod(999))).toBe(s);
    // Existing lines can still grow at the cap.
    expect(add(s, prod(1), 2)[1].quantity).toBe(3);
  });

  it('removes a line and clears the cart', () => {
    let s = add(add({}, prod(1)), prod(2));
    s = cartReducer(s, { type: 'remove', productId: 1 });
    expect(Object.keys(s)).toEqual(['2']);
    expect(cartReducer(s, { type: 'clear' })).toEqual({});
  });
});

function Probe() {
  const { lines, totalQuantity, add: addToCart } = useCart();
  return (
    <div>
      <span data-testid="qty">{totalQuantity}</span>
      <span data-testid="lines">{lines.length}</span>
      <button onClick={() => addToCart(prod(7), 2)}>add</button>
    </div>
  );
}

describe('persistence', () => {
  beforeEach(() => window.localStorage.clear());
  afterEach(() => vi.restoreAllMocks());

  it('round-trips through localStorage', async () => {
    const user = userEvent.setup();
    const first = render(
      <CartProvider>
        <Probe />
      </CartProvider>
    );
    await user.click(screen.getByText('add'));
    expect(screen.getByTestId('qty')).toHaveTextContent('2');
    first.unmount();

    render(
      <CartProvider>
        <Probe />
      </CartProvider>
    );
    expect(screen.getByTestId('qty')).toHaveTextContent('2');
  });

  it.each([
    ['invalid JSON', '{not json'],
    ['an array', '[1,2]'],
    ['a string', '"hi"'],
    ['null', 'null'],
  ])('ignores %s', (_n, raw) => {
    window.localStorage.setItem(STORAGE_KEY, raw);
    expect(loadCart()).toEqual({});
  });

  it('drops malformed lines but keeps valid ones, clamping quantity', () => {
    window.localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({
        1: { product: prod(1), quantity: 2 },
        2: { product: prod(2), quantity: 'x' },
        3: { product: { id: 3 }, quantity: 1 },
        4: { product: prod(4, { stock: 2 }), quantity: 50 },
        5: null,
        6: { product: prod(6, { priceCents: 1.5 }), quantity: 1 },
      })
    );
    const cart = loadCart();
    expect(Object.keys(cart).sort()).toEqual(['1', '4']);
    expect(cart[4].quantity).toBe(2);
  });

  it('still works when localStorage throws', async () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('denied');
    });
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('denied');
    });
    const user = userEvent.setup();
    render(
      <CartProvider>
        <Probe />
      </CartProvider>
    );
    await user.click(screen.getByText('add'));
    expect(screen.getByTestId('qty')).toHaveTextContent('2');
  });
});
