export const MAX_PRICE_CENTS = 9007199254740991n

/** No thousands separators, exponent notation or rounding. */
export function decimalToCents(text: string): number {
  const match = /^([0-9]+)(?:[.,]([0-9]{1,2}))?$/.exec(text.trim())
  if (!match) throw new Error('Ingresa un importe no negativo con hasta dos decimales, sin separadores de miles.')
  const digits = (match[1] + (match[2] ?? '').padEnd(2, '0')).replace(/^0+/, '') || '0'
  const maximum = MAX_PRICE_CENTS.toString()
  if (digits.length > maximum.length || (digits.length === maximum.length && digits > maximum)) {
    throw new Error('El precio máximo es 90071992547409.91.')
  }
  return Number(digits)
}

export function centsToDecimal(cents: number): string {
  if (!Number.isSafeInteger(cents) || cents < 0) throw new Error('Precio en céntimos inválido.')
  const value = BigInt(cents)
  return `${value / 100n}.${String(value % 100n).padStart(2, '0')}`
}

export const taxLabels = { '10': 'Gravado', '20': 'Exonerado', '30': 'Inafecto' } as const
