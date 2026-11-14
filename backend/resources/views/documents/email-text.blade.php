{{ $document->fiscal_snapshot['issuer']['legal_name'] }}
{{ $document->doc_type==='01'?'Factura':'Boleta' }} {{ $document->series_code }}-{{ str_pad((string)$document->number,8,'0',STR_PAD_LEFT) }}
Fecha: {{ $document->issue_date->format('d/m/Y') }}
Total: {{ \App\Services\DocumentPdfRenderer::amount($document->total_cents,$document->currency) }} {{ $document->currency }}
PDF adjunto.
Documento de demostración — emisión tributaria simulada.
Sin validez tributaria.

Plataforma: {{ config('app.name') }} · Facturación y cobranza para empresas.
