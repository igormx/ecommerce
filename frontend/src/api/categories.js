import { request } from './client.js';

/** @returns {Promise<string[]>} */
export async function listCategories() {
  const { data } = await request('GET', '/categories');
  return data;
}
