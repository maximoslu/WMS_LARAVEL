<?php

namespace App\Services\Deca;

use App\Models\DecaDocument;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use RuntimeException;

class DecaPdfService
{
    public function qr(string $url): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle(300, 4), new SvgImageBackEnd)))
            ->writeString($url);
    }

    public function render(DecaDocument $document): string
    {
        $pdf = Pdf::loadView('deca.pdf', [
            'document' => $document,
            'data' => $document->snapshot,
            'qr' => 'data:image/svg+xml;base64,'.base64_encode($this->qr($document->public_url)),
        ])->setPaper('a4')->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false]);
        $canvas = $pdf->getDomPDF()->getCanvas();
        $date = $document->issued_at->utc()->format('YmdHis');
        $canvas->add_info('CreationDate', 'D:'.$date.'Z');
        $canvas->add_info('ModDate', 'D:'.$date.'Z');
        $canvas->add_info('Title', 'DECA '.$document->number);
        $bytes = $pdf->output();
        if (strlen($bytes) > 5_000_000) {
            throw new RuntimeException('El PDF DECA supera el límite de 5 MB.');
        }

        return $bytes;
    }
}
