import { describe, expect, it } from 'vitest'
import { centsToDecimal, decimalToCents } from './money'
describe('precio decimal exacto', () => {
  it.each([['10', 1000], ['10.50', 1050], ['0.35', 35], ['0', 0], ['10,50', 1050], ['000.01', 1], [' 10 ', 1000], ['90071992547409.91', Number.MAX_SAFE_INTEGER]] as const)('%s a centimos', (text, expected) => {
    expect(decimalToCents(text)).toBe(expected)
    expect(decimalToCents(centsToDecimal(expected))).toBe(expected)
  })
  it.each(['-1', '1.001', '1e3', 'abc', '', '1,000', '1,234.50', '1.234,50', '.50', '1.', '+1', '90071992547409.92', '999999999999999999999999'])('rechaza %s', (text) => {
    expect(() => decimalToCents(text)).toThrow()
  })
})
