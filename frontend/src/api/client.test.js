import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError, request } from './client.js';

function jsonResponse(status, body) {
  return {
    status,
    ok: status >= 200 && status < 300,
    text: async () => JSON.stringify(body),
  };
}

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe('request', () => {
  it('unwraps data and meta on success', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse(200, {
          statusCode: 200,
          data: [{ id: 1 }],
          meta: { page: 1, size: 20, total: 1 },
        })
      )
    );

    const result = await request('GET', '/products');

    expect(result.data).toEqual([{ id: 1 }]);
    expect(result.meta).toEqual({ page: 1, size: 20, total: 1 });
  });

  it('leaves meta undefined when the envelope has no meta key', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(jsonResponse(200, { statusCode: 200, data: { id: 1 } }))
    );

    const result = await request('GET', '/products/1');

    expect(result.data).toEqual({ id: 1 });
    expect(result.meta).toBeUndefined();
  });

  it('returns { data: null, meta: undefined } on 204', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ status: 204, ok: true, text: async () => '' }));

    const result = await request('DELETE', '/products/1');

    expect(result).toEqual({ data: null, meta: undefined });
  });

  it.each([
    ['VALIDATION_ERROR', 400],
    ['RESOURCE_NOT_FOUND', 404],
    ['CONFLICT', 409],
    ['INSUFFICIENT_STOCK', 409],
  ])('maps a %s response to an ApiError with status, type, description and fields', async (type, status) => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse(status, {
          statusCode: status,
          error: { type, description: 'Something is wrong.', fields: { sku: 'bad sku' } },
        })
      )
    );

    await expect(request('POST', '/products', {})).rejects.toMatchObject({
      status,
      type,
      description: 'Something is wrong.',
      fields: { sku: 'bad sku' },
    });
    await expect(request('POST', '/products', {})).rejects.toBeInstanceOf(ApiError);
  });

  it('throws an ApiError with type NETWORK_ERROR when fetch rejects', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')));

    await expect(request('GET', '/products')).rejects.toBeInstanceOf(ApiError);
    await expect(request('GET', '/products')).rejects.toMatchObject({ type: 'NETWORK_ERROR' });
  });

  it('throws an ApiError with type SERVER_ERROR on a non-JSON body (e.g. an nginx error page)', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        status: 502,
        ok: false,
        text: async () => '<html><body>502 Bad Gateway</body></html>',
      })
    );

    await expect(request('GET', '/products')).rejects.toBeInstanceOf(ApiError);
    await expect(request('GET', '/products')).rejects.toMatchObject({ type: 'SERVER_ERROR', status: 502 });
  });

  it('sends a JSON content-type and body only when a body is given', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse(201, { statusCode: 201, data: { id: 1 } }));
    vi.stubGlobal('fetch', fetchMock);

    await request('POST', '/products', { sku: 'ABC' });

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/products',
      expect.objectContaining({
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ sku: 'ABC' }),
      })
    );
  });
});

describe('ApiError', () => {
  it('is an Error carrying status, type, description and fields', () => {
    const error = new ApiError({ status: 400, type: 'VALIDATION_ERROR', description: 'bad', fields: { q: 'x' } });

    expect(error).toBeInstanceOf(Error);
    expect(error.status).toBe(400);
    expect(error.type).toBe('VALIDATION_ERROR');
    expect(error.description).toBe('bad');
    expect(error.fields).toEqual({ q: 'x' });
  });
});
