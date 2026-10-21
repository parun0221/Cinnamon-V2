import { describe, expect, it } from 'vitest'
import { formatMoney, formatQuantity } from './number'

describe('formatQuantity', () => {
  it('memformat 3 desimal dengan koma sebagai pemisah desimal', () => {
    expect(formatQuantity('24.500')).toBe('24,500')
    expect(formatQuantity(7)).toBe('7,000')
  })

  it('mengembalikan nilai aman untuk input tidak valid', () => {
    expect(formatQuantity('bukan-angka')).toBe('0,000')
  })
})

describe('formatMoney', () => {
  it('memformat rupiah dengan pemisah ribuan titik', () => {
    expect(formatMoney('42000.00')).toBe('Rp 42.000')
    expect(formatMoney(1500000)).toBe('Rp 1.500.000')
  })

  it('membulatkan ke rupiah terdekat tanpa desimal', () => {
    expect(formatMoney('42000.60')).toBe('Rp 42.001')
  })

  it('mengembalikan nilai aman untuk input tidak valid', () => {
    expect(formatMoney('bukan-angka')).toBe('Rp 0')
  })
})
