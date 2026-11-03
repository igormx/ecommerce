// Money is always integer cents on the wire (see README.md "API"). This
// module is the only place that converts between cents and a display /
// input string, so rounding and formatting stay consistent everywhere.

const CURRENCY_FORMATTER = new Intl.NumberFormat('en-US', {
  style: 'currency',
  currency: 'USD',
});

/** @param {number} cents @returns {string} e.g. formatCents(1999) === '$19.99' */
export function formatCents(cents) {
  return CURRENCY_FORMATTER.format(cents / 100);
}

// Whole dollars, optionally followed by 1 or 2 decimal digits. No sign, no
// exponents, no leading/trailing whitespace (callers should trim first if
// they want to tolerate it).
const PRICE_PATTERN = /^\d+(\.\d{1,2})?$/;

/**
 * Converts a decimal dollar string to integer cents using string/integer
 * arithmetic (never `parseFloat(x) * 100`, which can misround e.g. 19.999999
 * for inputs like "0.1" + "0.2"). Returns `null` for anything that isn't a
 * non-negative amount with at most two decimal places.
 * @param {string} value
 * @returns {number | null}
 */
export function parsePriceToCents(value) {
  if (typeof value !== 'string') return null;
  const trimmed = value.trim();
  if (!PRICE_PATTERN.test(trimmed)) return null;

  const [whole, fraction = ''] = trimmed.split('.');
  const paddedFraction = fraction.padEnd(2, '0');
  return Number(whole) * 100 + Number(paddedFraction);
}
