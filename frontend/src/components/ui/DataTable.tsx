import type { ReactNode } from 'react'
export default function DataTable({ label, headers, children }: { label: string; headers: string[]; children: ReactNode }) {
  return <div className="table-region" role="region" aria-label={label} tabIndex={0}>
    <table className="data-table"><caption className="sr-only">{label}</caption><thead><tr>{headers.map((header) => <th key={header} scope="col" className={header.startsWith('Precio') || header.startsWith('Último') ? 'numeric' : undefined}>{header}</th>)}</tr></thead><tbody>{children}</tbody></table>
  </div>
}
