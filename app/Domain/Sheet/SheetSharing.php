<?php

namespace App\Domain\Sheet;

use App\Models\Sheet;
use App\Models\SheetShare;
use App\Models\User;

/**
 * Compartir una hoja (Fase 7, §10): con una persona concreta o con un enlace.
 *
 *   - Enlace de LECTURA: quien lo tenga ve la hoja, haya entrado o no en la
 *     aplicación. Se pinta la vista de impresión, de solo lectura.
 *   - Enlace de EDICIÓN: pide entrar, y entonces convierte el enlace en un
 *     permiso de esa persona (sheet_shares con user_id), que es lo que mira
 *     SheetPolicy. Así se puede ver quién tiene acceso y quitárselo.
 *
 * Los enlaces llevan un token aleatorio de 32 bytes (§10) y pueden caducar.
 * Solo comparte el dueño de la hoja.
 */
final class SheetSharing
{
    public const ABILITIES = ['view', 'edit'];

    public function createLink(Sheet $sheet, User $actor, string $ability, ?int $days = null): SheetShare
    {
        $this->mustOwn($sheet, $actor);
        $this->ability($ability);

        return SheetShare::create([
            'sheet_id' => $sheet->id,
            'token' => bin2hex(random_bytes(32)),
            'ability' => $ability,
            'expires_at' => $days ? now()->addDays(max(1, min(365, $days))) : null,
        ]);
    }

    public function shareWith(Sheet $sheet, User $actor, string $email, string $ability): SheetShare
    {
        $this->mustOwn($sheet, $actor);
        $this->ability($ability);

        $user = User::where('email', User::normalizeEmail($email))->first()
            ?? throw new SharingException('No hay nadie con ese correo en la aplicación.');

        if ($user->id === $sheet->owner_id) {
            throw new SharingException('Ya es tuya.');
        }

        return SheetShare::updateOrCreate(
            ['sheet_id' => $sheet->id, 'user_id' => $user->id],
            ['ability' => $ability, 'expires_at' => null],
        );
    }

    public function revoke(Sheet $sheet, User $actor, int $shareId): void
    {
        $this->mustOwn($sheet, $actor);

        SheetShare::where('sheet_id', $sheet->id)->whereKey($shareId)->delete();
    }

    /** El enlace de ese token, si existe y no ha caducado. */
    public function resolve(string $token): ?SheetShare
    {
        if (! preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }

        $share = SheetShare::with('sheet')->where('token', $token)->whereNull('user_id')->first();

        return $share && ! $share->isExpired() && $share->sheet ? $share : null;
    }

    /**
     * Abrir un enlace de edición estando dentro: la persona pasa a tener
     * permiso de edición (hasta la misma fecha que el enlace).
     */
    public function accept(SheetShare $link, User $user): Sheet
    {
        $sheet = $link->sheet;

        if ($link->ability === 'edit' && $user->id !== $sheet->owner_id) {
            $existing = SheetShare::where('sheet_id', $sheet->id)->where('user_id', $user->id)->first();

            // Nunca rebaja un permiso: si ya podía editar sin caducidad, se queda así.
            if (! $existing || $existing->ability !== 'edit' || $existing->expires_at !== null) {
                SheetShare::updateOrCreate(
                    ['sheet_id' => $sheet->id, 'user_id' => $user->id],
                    ['ability' => 'edit', 'expires_at' => $link->expires_at],
                );
            }
        }

        return $sheet;
    }

    private function mustOwn(Sheet $sheet, User $actor): void
    {
        if (! $actor->can('share', $sheet)) {
            throw new SharingException('Solo quien creó la hoja puede compartirla.');
        }
    }

    private function ability(string $ability): void
    {
        if (! in_array($ability, self::ABILITIES, true)) {
            throw new SharingException('Permiso no válido.');
        }
    }
}
