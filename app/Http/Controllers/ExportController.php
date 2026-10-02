<?php

namespace App\Http\Controllers;

use App\Domain\Transfer\SheetTransfer;
use App\Domain\Transfer\TemplateTransfer;
use App\Models\Sheet;
use App\Models\Template;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Descarga de plantillas y hojas en JSON (§6.4). Quien puede ver algo puede
 * llevárselo: una plantilla pública también se puede exportar para copiarla.
 */
class ExportController extends Controller
{
    public function template(Template $template): Response
    {
        Gate::authorize('view', $template);

        return $this->download(app(TemplateTransfer::class)->export($template), 'plantilla-'.$template->slug);
    }

    public function sheet(Sheet $sheet): Response
    {
        Gate::authorize('view', $sheet);

        return $this->download(app(SheetTransfer::class)->export($sheet), 'hoja-'.(Str::slug($sheet->name) ?: 'sin-nombre'));
    }

    private function download(array $payload, string $name): Response
    {
        return response()->streamDownload(
            fn () => print json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $name.'.json',
            ['Content-Type' => 'application/json'],
        );
    }
}
