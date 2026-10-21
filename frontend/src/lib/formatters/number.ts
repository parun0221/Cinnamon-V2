/**
 * Number formatting without relying on the PHP `intl` extension or on the
 * browser's `Intl` locale data. Money is grouped with dots and rounded to the
 * nearest rupiah; quantity keeps the three decimals the API contract requires.
 */

export function formatQuantity(value: string | number): string {
  const numeric = typeof value === 'number' ? value : Number.parseFloat(value)

  if (!Number.isFinite(numeric)) {
    return '0,000'
  }

  return numeric.toFixed(3).replace('.', ',')
}

export function formatMoney(value: string | number): string {
  const numeric = typeof value === 'number' ? value : Number.parseFloat(value)

  if (!Number.isFinite(numeric)) {
    return 'Rp 0'
  }

  const rounded = Math.round(numeric)
  const grouped = rounded.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.')

  return `Rp ${grouped}`
}
