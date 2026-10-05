<?php

namespace App\Http\Controllers;

use App\Domain\Campaign\CampaignException;
use App\Domain\Campaign\Campaigns;
use Illuminate\Http\RedirectResponse;

/**
 * Enlace de invitación (/mesas/unirse/{código}): quien lo abre entra en la
 * mesa como jugador. Si no ha entrado en la aplicación, el middleware `auth`
 * le pide entrar y le devuelve aquí.
 */
class JoinCampaignController extends Controller
{
    public function __invoke(string $code, Campaigns $campaigns): RedirectResponse
    {
        try {
            $campaign = $campaigns->join(auth()->user(), $code);
        } catch (CampaignException $e) {
            return redirect()->route('campaigns.index')->withErrors(['code' => $e->getMessage()]);
        }

        return redirect()->route('campaigns.show', $campaign);
    }
}
