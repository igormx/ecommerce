import { createContext, useContext, useEffect, useMemo, useReducer } from 'react';

// Cart state: { [productId]: { product: { id, name, sku, priceCents, stock }, quantity } }.
// Duplicate adds merge into one line (the API rejects duplicate productIds).

export const STORAGE_KEY = 'ecommerce.cart.v1';
export const MAX_LINES = 50; // API limit on purchase items
export const MAX_QUANTITY = 1000; // API limit per line

/** Highest quantity the UI allows for a product. */
export function maxQuantity(product) {
  return Math.max(0, Math.min(product.stock, MAX_QUANTITY));
}

function clampQuantity(quantity, product) {
  const max = maxQuantity(product);
  const n = Number.isFinite(quantity) ? Math.trunc(quantity) : 1;
  return Math.min(Math.max(n, 1), max);
}

function snapshot(p) {
  return { id: p.id, name: p.name, sku: p.sku, priceCents: p.priceCents, stock: p.stock };
}

export function cartReducer(state, action) {
  switch (action.type) {
    case 'add': {
      const product = snapshot(action.product);
      if (maxQuantity(product) < 1) return state; // out of stock
      const existing = state[product.id];
      if (!existing && Object.keys(state).length >= MAX_LINES) return state;
      const wanted = (existing ? existing.quantity : 0) + (action.quantity ?? 1);
      return { ...state, [product.id]: { product, quantity: clampQuantity(wanted, product) } };
    }
    case 'setQuantity': {
      const line = state[action.productId];
      if (!line) return state;
      return {
        ...state,
        [action.productId]: { ...line, quantity: clampQuantity(action.quantity, line.product) },
      };
    }
    case 'remove': {
      if (!state[action.productId]) return state;
      const next = { ...state };
      delete next[action.productId];
      return next;
    }
    case 'clear':
      return {};
    case 'replace':
      // Used after refreshing prices/stock: the caller supplies the full new state.
      return action.state;
    default:
      return state;
  }
}

function isValidLine(line) {
  const p = line?.product;
  return (
    p &&
    Number.isInteger(p.id) &&
    p.id >= 1 &&
    typeof p.name === 'string' &&
    typeof p.sku === 'string' &&
    Number.isInteger(p.priceCents) &&
    p.priceCents >= 0 &&
    Number.isInteger(p.stock) &&
    p.stock >= 1 &&
    Number.isInteger(line.quantity) &&
    line.quantity >= 1
  );
}

/** Reads the persisted cart; anything malformed is ignored (empty cart or dropped line). */
export function loadCart() {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    if (!raw) return {};
    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return {};
    const cart = {};
    for (const line of Object.values(parsed)) {
      if (!isValidLine(line) || cart[line.product.id]) continue;
      if (Object.keys(cart).length >= MAX_LINES) break;
      const product = snapshot(line.product);
      cart[product.id] = { product, quantity: clampQuantity(line.quantity, product) };
    }
    return cart;
  } catch {
    return {};
  }
}

function saveCart(cart) {
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(cart));
  } catch {
    // Storage may be unavailable or full; the cart still works in memory.
  }
}

const CartContext = createContext(null);

export function CartProvider({ children }) {
  const [cart, dispatch] = useReducer(cartReducer, undefined, loadCart);

  useEffect(() => {
    saveCart(cart);
  }, [cart]);

  const value = useMemo(() => {
    const lines = Object.values(cart);
    return {
      cart,
      lines,
      totalQuantity: lines.reduce((sum, l) => sum + l.quantity, 0),
      totalCents: lines.reduce((sum, l) => sum + l.product.priceCents * l.quantity, 0),
      atLineCap: lines.length >= MAX_LINES,
      add: (product, quantity = 1) => dispatch({ type: 'add', product, quantity }),
      setQuantity: (productId, quantity) => dispatch({ type: 'setQuantity', productId, quantity }),
      remove: (productId) => dispatch({ type: 'remove', productId }),
      clear: () => dispatch({ type: 'clear' }),
      replace: (state) => dispatch({ type: 'replace', state }),
    };
  }, [cart]);

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export function useCart() {
  const ctx = useContext(CartContext);
  if (!ctx) throw new Error('useCart must be used inside <CartProvider>');
  return ctx;
}
