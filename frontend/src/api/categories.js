import { request } from './client.js';

export async function listCategories() {
  const { data } = await request('GET', '/categories');
  return data;
}
