<?php

namespace App\Policies;

use App\Models\Sheet;
use App\Models\User;

class SheetPolicy
{
    public function view(?User $user, Sheet $sheet): bool
    {
        if (in_array($sheet->visibility, ['public', 'unlisted'], true)) {
            return true;
        }

        if (! $user) {
            return false;
        }

        if ($user->id === $sheet->owner_id) {
            return true;
        }

        if ($this->sharedWith($user, $sheet, ['view', 'edit'])) {
            return true;
        }

        return $this->throughCampaign($user, $sheet, gmOnly: false);
    }

    public function update(User $user, Sheet $sheet): bool
    {
        if ($user->id === $sheet->owner_id) {
            return true;
        }

        if ($this->sharedWith($user, $sheet, ['edit'])) {
            return true;
        }

        // El DJ puede editar las hojas de su mesa si la mesa lo permite.
        return $this->throughCampaign($user, $sheet, gmOnly: true);
    }

    public function delete(User $user, Sheet $sheet): bool
    {
        return $user->id === $sheet->owner_id;
    }

    public function share(User $user, Sheet $sheet): bool
    {
        return $user->id === $sheet->owner_id;
    }

    // ---------------------------------------------------------------- internals

    private function sharedWith(User $user, Sheet $sheet, array $abilities): bool
    {
        return $sheet->shares()
            ->where('user_id', $user->id)
            ->whereIn('ability', $abilities)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }

    /**
     * Acceso derivado de compartir mesa. Con gmOnly, solo el DJ y solo si los
     * ajustes de la mesa lo permiten (por defecto sí).
     */
    private function throughCampaign(User $user, Sheet $sheet, bool $gmOnly): bool
    {
        foreach ($sheet->campaigns as $campaign) {
            $role = $campaign->roleOf($user);

            if ($role === null) {
                continue;
            }

            if ($gmOnly) {
                if ($role === 'gm' && ($campaign->settings['gm_can_edit_sheets'] ?? true)) {
                    return true;
                }

                continue;
            }

            // El DJ ve todas las hojas de su mesa. Los demás solo abren las
            // compartidas «completas»: con «resumen» ven la tarjeta, no la hoja.
            if ($role === 'gm' || $campaign->pivot?->share_level === 'full') {
                return true;
            }
        }

        return false;
    }
}
