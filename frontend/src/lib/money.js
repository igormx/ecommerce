// Money is integer cents on the wire; all conversion lives here.

const CURRENCY_FORMATTER = new Intl.NumberFormat('en-US', {
  style: 'currency',
  currency: 'USD',
});

export function formatCents(cents) {
  return CURRENCY_FORMATTER.format(cents / 100);
}

// Whole dollars, optionally followed by 1 or 2 decimal digits. No sign, no
// exponents, no leading/trailing whitespace (callers should trim first if
// they want to tolerate it).
const PRICE_PATTERN = /^\d+(\.\d{1,2})?$/;

// Avoids parseFloat(x) * 100 misrounding. Returns null if invalid.
export function parsePriceToCents(value) {
  if (typeof value !== 'string') return null;
  const trimmed = value.trim();
  if (!PRICE_PATTERN.test(trimmed)) return null;

  const [whole, fraction = ''] = trimmed.split('.');
  const paddedFraction = fraction.padEnd(2, '0');
  return Number(whole) * 100 + Number(paddedFraction);
}
