<?php

namespace App\Tax;

use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * A capital gains report as a PDF (dompdf). It prints the same CgtReport the
 * page shows and works nothing out. dompdf fetches nothing over the network
 * and runs no script.
 */
class TaxReportPdf
{
    public function pdf(User $user, CgtReport $report): string
    {
        $dirs = storage_path('app/dompdf');

        if (! is_dir($dirs)) {
            mkdir($dirs, 0775, true);
        }

        $dompdf = new Dompdf(new Options([
            'isRemoteEnabled' => false,
            'isJavascriptEnabled' => false,
            'isPhpEnabled' => false,
            'fontDir' => $dirs,
            'fontCache' => $dirs,
            'tempDir' => $dirs,
            'defaultFont' => 'DejaVu Sans',
        ]));
        $dompdf->setPaper('A4');
        $dompdf->loadHtml($this->html($user, $report));
        $dompdf->render();

        $footer = config('app.name')." · Capital gains {$report->year->label()} · {$user->name} · Page {PAGE_NUM} of {PAGE_COUNT}";
        $dompdf->getCanvas()->page_text(36, 810, $footer, $dompdf->getFontMetrics()->getFont('DejaVu Sans'), 8, [0.45, 0.45, 0.45]);

        return (string) $dompdf->output();
    }

    /**
     * The report as dompdf will see it.
     */
    public function html(User $user, CgtReport $report): string
    {
        return view('tax-report.pdf', ['user' => $user, 'report' => $report, 'generatedAt' => now()])->render();
    }
}
