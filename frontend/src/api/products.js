import { request } from './client.js';

function toQuery(params) {
  const qs = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') qs.set(key, String(value));
  }
  const s = qs.toString();
  return s ? `?${s}` : '';
}

/** @returns {Promise<{ items: object[], meta: { page: number, size: number, total: number } }>} */
export async function listProducts({ page, size } = {}) {
  const { data, meta } = await request('GET', `/products${toQuery({ page, size })}`);
  return { items: data, meta };
}

/** At least one of `q` / `category` must be set (the API rejects neither). */
export async function searchProducts({ q, category, page, size } = {}) {
  const { data, meta } = await request(
    'GET',
    `/products/search${toQuery({ q, category, page, size })}`
  );
  return { items: data, meta };
}

export async function getProduct(id) {
  const { data } = await request('GET', `/products/${encodeURIComponent(id)}`);
  return data;
}

/**
 * Body keys are exactly what the API accepts (it rejects unknown keys):
 * sku, name, description, category, priceCents, stock, weightKg.
 */
export async function createProduct(body) {
  const { data } = await request('POST', '/products', body);
  return data;
}

/** Full replace: same body as `createProduct`. */
export async function updateProduct(id, body) {
  const { data } = await request('PUT', `/products/${encodeURIComponent(id)}`, body);
  return data;
}

/** Soft delete; resolves with null (204). */
export async function deleteProduct(id) {
  await request('DELETE', `/products/${encodeURIComponent(id)}`);
}
