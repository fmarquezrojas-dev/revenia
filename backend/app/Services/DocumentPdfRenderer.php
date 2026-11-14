<?php

namespace App\Services;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Models\Document;
use Dompdf\Dompdf;
use Dompdf\Options;

class DocumentPdfRenderer
{
    public static function amount(int $cents, string $currency): string
    {
        return (new Money($cents, Currency::from($currency)))->decimal();
    }

    public function render(Document $doc): string
    {
        $snapshot = $doc->fiscal_snapshot;
        if ($doc->status !== 'accepted' || ! $doc->number || ! $doc->series_code || ! is_array($snapshot) || ! isset($snapshot['issuer']['legal_name'],$snapshot['issuer']['ruc'],$snapshot['issuer']['address']) || ! array_key_exists('customer', $snapshot) || ($doc->doc_type === '01' && ! isset($snapshot['customer']['name'],$snapshot['customer']['doc_number']))) {
            throw new \DomainException('snapshot_incomplete');
        }
        $doc->load('items');
        if ($doc->items->isEmpty() || $doc->items->count() > 50) {
            throw new \DomainException('snapshot_incomplete');
        }
        $temp = storage_path('framework/cache/pdf');
        if (! is_dir($temp) && ! mkdir($temp, 0700, true) && ! is_dir($temp)) {
            throw new \RuntimeException('render_storage');
        }
        $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'chroot' => [base_path('vendor/dompdf/dompdf/lib/fonts')], 'tempDir' => $temp, 'fontCache' => $temp, 'isFontSubsettingEnabled' => true]);
        $options->setAllowedProtocols(['file://']);
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4');
        $pdf->loadHtml(view('documents.pdf', ['doc' => $doc, 'snapshot' => $snapshot])->render(), 'UTF-8');
        $pdf->render();
        $pdf->getCanvas()->page_text(42, 810, 'Pagina {PAGE_NUM} de {PAGE_COUNT}', $pdf->getFontMetrics()->getFont('DejaVu Sans'), 8, [0.3, 0.3, 0.3]);

        return $pdf->output();
    }
}
