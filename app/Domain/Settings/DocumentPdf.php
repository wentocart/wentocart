<?php

namespace App\Domain\Settings;

use Dompdf\Dompdf;
use Dompdf\Options;

final class DocumentPdf
{
    public function output(string $html, string $paper = 'a4'): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper($paper, 'portrait');
        $dompdf->render();

        return $dompdf->output() ?: '';
    }
}
