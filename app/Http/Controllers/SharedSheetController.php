<?php

namespace App\Http\Controllers;

use App\Domain\Sheet\SheetSharing;
use Illuminate\Http\Request;

/**
 * Enlaces compartidos de hoja (/compartida/{token}, Fase 7).
 *
 *   - De lectura: se ve sin entrar en la aplicación, en la vista de impresión
 *     (solo lectura, todas las pestañas, con PDF).
 *   - De edición: pide entrar; después, la persona tiene permiso para editar
 *     y se le abre el editor normal.
 *
 * Un token que no existe o ha caducado es un 404: no se distingue un caso del
 * otro, para no dar pistas a quien prueba tokens.
 */
class SharedSheetController extends Controller
{
    public function show(Request $request, string $token, SheetSharing $sharing)
    {
        $link = $sharing->resolve($token) ?? abort(404);

        if ($link->ability === 'edit') {
            if (! $request->user()) {
                return redirect()->guest(route('login'));
            }

            return redirect()->route('sheets.edit', $sharing->accept($link, $request->user()));
        }

        $print = app(SheetPrintController::class);

        return view('print.sheet', [...$print->viewData($request, $link->sheet, pdf: false), 'sharedToken' => $token]);
    }

    public function pdf(Request $request, string $token, SheetSharing $sharing)
    {
        $link = $sharing->resolve($token) ?? abort(404);
        $print = app(SheetPrintController::class);

        return $print->renderPdf($link->sheet, $print->viewData($request, $link->sheet, pdf: true));
    }
}
