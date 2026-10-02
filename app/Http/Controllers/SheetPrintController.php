<?php

namespace App\Http\Controllers;

use App\Domain\Sheet\SheetPrinter;
use App\Domain\Theme\SheetTheme;
use App\Models\Sheet;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Impresión y PDF de una hoja (§6.4).
 *
 * El PDF lo genera dompdf, PHP puro: funciona en cualquier hosting, sin
 * navegador headless. Con el acceso remoto desactivado: no puede pedir nada
 * por red, las imágenes le llegan incrustadas (SheetPrinter::embed).
 *
 * ?marca=1 añade una marca de agua con el nombre de la plantilla.
 */
class SheetPrintController extends Controller
{
    public function show(Request $request, Sheet $sheet)
    {
        Gate::authorize('view', $sheet);

        return view('print.sheet', $this->viewData($request, $sheet, pdf: false));
    }

    public function pdf(Request $request, Sheet $sheet): Response
    {
        Gate::authorize('view', $sheet);

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(base_path());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('print.sheet', $this->viewData($request, $sheet, pdf: true))->render(), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        $filename = Str::slug($sheet->name ?: 'hoja').'.pdf';

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    private function viewData(Request $request, Sheet $sheet, bool $pdf): array
    {
        $sheet->loadMissing(['version', 'template']);

        return [
            'sheet' => $sheet,
            'tabs' => (new SheetPrinter($sheet))->tabs(embedImages: $pdf),
            'theme' => SheetTheme::forSheet($sheet),
            'pdf' => $pdf,
            'watermark' => $request->boolean('marca') ? ($sheet->schema()->template['name'] ?? 'Pergamino') : null,
        ];
    }
}
