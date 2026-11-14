import type { Totals } from '../lib/documents'
import { centsToDecimal } from '../lib/money'
export default function DocumentTotals({ totals, currency }: {totals: Totals; currency: string}) {
 return <dl className="document-totals">{([['taxable_cents','Gravado'],['exempt_cents','Exonerado'],['unaffected_cents','Inafecto'],['igv_cents','IGV'],['total_cents','Total']] as const).map(([key,label])=><div key={key}><dt>{label}</dt><dd>{centsToDecimal(totals[key])} {currency}</dd></div>)}</dl>
}
