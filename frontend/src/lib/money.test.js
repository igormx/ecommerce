import { describe, expect, it } from 'vitest';
import { formatCents, parsePriceToCents } from './money.js';

describe('formatCents', () => {
  it.each([
    [0, '$0.00'],
    [5, '$0.05'],
    [100, '$1.00'],
    [123456789, '$1,234,567.89'],
  ])('formats %i cents as %s', (cents, expected) => {
    expect(formatCents(cents)).toBe(expected);
  });
});

describe('parsePriceToCents', () => {
  it.each([
    ['19.99', 1999],
    ['19', 1900],
    ['0.1', 10],
  ])('accepts "%s" -> %i cents', (input, expected) => {
    expect(parsePriceToCents(input)).toBe(expected);
  });

  it.each([['19.999'], ['-1'], ['abc'], [''], ['1e3']])('rejects "%s"', (input) => {
    expect(parsePriceToCents(input)).toBeNull();
  });
});
