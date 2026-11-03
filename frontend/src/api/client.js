// Thin wrapper around fetch for the /api backend.
//
// Success responses are `{ statusCode, data, meta? }`; errors are
// `{ statusCode, error: { type, description, fields? } }`. `request()` unwraps both.

export class ApiError extends Error {
  constructor({ status, type, description, fields }) {
    super(description || type || 'Request failed');
    this.name = 'ApiError';
    this.status = status;
    this.type = type;
    this.description = description;
    this.fields = fields;
  }
}

function networkError() {
  return new ApiError({
    status: 0,
    type: 'NETWORK_ERROR',
    description: 'Could not reach the server. Check your connection and try again.',
  });
}

function serverError(status) {
  return new ApiError({
    status,
    type: 'SERVER_ERROR',
    description: 'The server returned an unexpected response.',
  });
}

export async function request(method, path, body) {
  const hasBody = body !== undefined;

  let response;
  try {
    response = await fetch(`/api${path}`, {
      method,
      headers: hasBody ? { 'Content-Type': 'application/json' } : undefined,
      body: hasBody ? JSON.stringify(body) : undefined,
    });
  } catch {
    throw networkError();
  }

  if (response.status === 204) {
    return { data: null, meta: undefined };
  }

  const text = await response.text();
  let envelope = null;
  if (text) {
    try {
      envelope = JSON.parse(text);
    } catch {
      // Non-JSON body: e.g. nginx's HTML page for a 502/504. Never let a raw
      // SyntaxError escape; surface it as a server error instead.
      throw serverError(response.status);
    }
  }

  if (!response.ok) {
    const error = envelope?.error ?? {};
    throw new ApiError({
      status: response.status,
      type: error.type || 'SERVER_ERROR',
      description: error.description || 'Something went wrong.',
      fields: error.fields,
    });
  }

  return { data: envelope ? envelope.data : null, meta: envelope ? envelope.meta : undefined };
}
