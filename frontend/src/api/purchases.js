import { request } from './client.js';

/**
 * Sends only `{ productId, quantity }` per line (the API rejects unknown keys).
 * A declined payment is NOT an error: it resolves with an order whose
 * `status` is `"FAILED"`.
 */
export async function createPurchase(items) {
  const body = { items: items.map(({ productId, quantity }) => ({ productId, quantity })) };
  const { data } = await request('POST', '/purchases', body);
  return data;
}

export async function getOrder(id) {
  const { data } = await request('GET', `/orders/${encodeURIComponent(id)}`);
  return data;
}
