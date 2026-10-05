<?php

namespace App\Domain\Campaign;

use App\Domain\Theme\Theme;
use App\Models\Campaign;
use App\Models\CampaignMember;
use App\Models\CampaignNote;
use App\Models\Sheet;
use App\Models\Template;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mesas (§8): crear, unirse por código, roles, hojas y notas.
 *
 * Toda la lógica vive aquí, como la del constructor en TemplateEditor: el
 * componente Livewire solo traduce clics. Cada operación comprueba quién la
 * hace; los permisos generales (ver, editar la mesa) los dicen las policies.
 *
 * Roles: gm (quien la crea; solo uno), player y spectator. Los espectadores
 * miran: no tiran, no añaden hojas.
 *
 * Nivel de compartición de cada hoja en la mesa:
 *   full     los demás pueden abrirla (solo lectura, salvo el DJ)
 *   summary  solo se ve su tarjeta resumen (los campos is_summary)
 *   hidden   solo el DJ sabe que está
 */
final class Campaigns
{
    public const SHARE_LEVELS = ['full', 'summary', 'hidden'];

    public function create(User $gm, string $name, ?string $description = null, ?int $templateId = null): Campaign
    {
        $name = trim($name);

        if ($name === '') {
            throw new CampaignException('La mesa necesita un nombre.');
        }

        return DB::transaction(function () use ($gm, $name, $description, $templateId) {
            $campaign = Campaign::create([
                'gm_id' => $gm->id,
                'name' => mb_substr($name, 0, 160),
                'description' => $this->text($description, 2000),
                'join_code' => Campaign::makeJoinCode(),
                'default_template_id' => $this->usableTemplate($gm, $templateId),
                'settings' => ['gm_can_edit_sheets' => true],
            ]);

            // El DJ también es miembro: así «mis mesas» es una sola consulta.
            CampaignMember::create(['campaign_id' => $campaign->id, 'user_id' => $gm->id, 'role' => 'gm', 'joined_at' => now()]);

            return $campaign;
        });
    }

    public function join(User $user, string $code): Campaign
    {
        $campaign = Campaign::findByJoinCode($code) ?? throw new CampaignException('No hay ninguna mesa con ese código.');

        if ($campaign->is_archived) {
            throw new CampaignException('Esa mesa está archivada.');
        }

        if ($campaign->roleOf($user) === null) {
            CampaignMember::create(['campaign_id' => $campaign->id, 'user_id' => $user->id, 'role' => 'player', 'joined_at' => now()]);
        }

        return $campaign;
    }

    /** Solo el DJ cambia roles; no se puede nombrar a otro DJ ni cambiarse a sí mismo. */
    public function setRole(Campaign $campaign, User $actor, int $userId, string $role): void
    {
        $this->mustBeGm($campaign, $actor);

        if (! in_array($role, ['player', 'spectator'], true)) {
            throw new CampaignException('Ese rol no existe.');
        }

        if ($userId === $campaign->gm_id) {
            throw new CampaignException('El DJ no puede cambiar de rol.');
        }

        $this->member($campaign, $userId)->update(['role' => $role]);
    }

    /** Echar a alguien (el DJ) o irse (uno mismo). Sus hojas salen de la mesa. */
    public function removeMember(Campaign $campaign, User $actor, int $userId): void
    {
        if ($actor->id !== $userId) {
            $this->mustBeGm($campaign, $actor);
        }

        if ($userId === $campaign->gm_id) {
            throw new CampaignException('El DJ no puede irse de su propia mesa.');
        }

        DB::transaction(function () use ($campaign, $userId) {
            $this->member($campaign, $userId)->delete();
            $campaign->sheets()->detach(Sheet::where('owner_id', $userId)->pluck('id'));
        });
    }

    public function addSheet(Campaign $campaign, User $actor, Sheet $sheet, string $shareLevel = 'summary'): void
    {
        if (! in_array($campaign->roleOf($actor), ['gm', 'player'], true)) {
            throw new CampaignException('Los espectadores no pueden llevar hojas a la mesa.');
        }

        if ($sheet->owner_id !== $actor->id) {
            throw new CampaignException('Solo puedes llevar a la mesa tus propias hojas.');
        }

        $this->shareLevel($shareLevel);

        $campaign->sheets()->syncWithoutDetaching([
            $sheet->id => ['share_level' => $shareLevel, 'position' => $campaign->sheets()->count()],
        ]);
    }

    /** El dueño de la hoja decide cuánto se ve; el DJ también puede. */
    public function setShareLevel(Campaign $campaign, User $actor, Sheet $sheet, string $shareLevel): void
    {
        $this->shareLevel($shareLevel);
        $this->mustOwnOrGm($campaign, $actor, $sheet);

        $campaign->sheets()->updateExistingPivot($sheet->id, ['share_level' => $shareLevel]);
    }

    public function removeSheet(Campaign $campaign, User $actor, Sheet $sheet): void
    {
        $this->mustOwnOrGm($campaign, $actor, $sheet);
        $campaign->sheets()->detach($sheet->id);
    }

    /**
     * Ajustes del DJ: datos de la mesa, si puede editar las hojas, la
     * plantilla sugerida y el tema de la mesa (§6.3, la capa intermedia).
     */
    public function updateSettings(Campaign $campaign, User $actor, array $attrs): Campaign
    {
        $this->mustBeGm($campaign, $actor);
        $data = [];

        if (array_key_exists('name', $attrs)) {
            $name = trim((string) $attrs['name']);
            $data['name'] = $name === '' ? $campaign->name : mb_substr($name, 0, 160);
        }

        if (array_key_exists('description', $attrs)) {
            $data['description'] = $this->text($attrs['description'], 2000);
        }

        if (array_key_exists('default_template_id', $attrs)) {
            $data['default_template_id'] = $this->usableTemplate($actor, is_numeric($attrs['default_template_id']) ? (int) $attrs['default_template_id'] : null);
        }

        $settings = $campaign->settings ?? [];
        if (array_key_exists('gm_can_edit_sheets', $attrs)) {
            $settings['gm_can_edit_sheets'] = (bool) $attrs['gm_can_edit_sheets'];
        }
        $data['settings'] = $settings;

        if (array_key_exists('theme_preset', $attrs)) {
            $data['theme_override'] = Theme::sanitize(['preset' => $attrs['theme_preset']]) ?: null;
        }

        $campaign->update($data);

        return $campaign->fresh();
    }

    public function regenerateCode(Campaign $campaign, User $actor): string
    {
        $this->mustBeGm($campaign, $actor);
        $campaign->update(['join_code' => Campaign::makeJoinCode()]);

        return $campaign->join_code;
    }

    // --------------------------------------------------------------- notas

    public function saveNote(Campaign $campaign, User $actor, ?int $noteId, string $title, string $body, bool $gmOnly): CampaignNote
    {
        $role = $campaign->roleOf($actor);

        if (! in_array($role, ['gm', 'player'], true)) {
            throw new CampaignException('Los espectadores no escriben notas.');
        }

        // Solo el DJ escribe notas privadas.
        $gmOnly = $gmOnly && $role === 'gm';

        $attrs = [
            'title' => $this->text($title, 160),
            'body' => mb_substr($body, 0, 20000),
            'is_gm_only' => $gmOnly,
        ];

        if ($noteId) {
            $note = $this->note($campaign, $actor, $noteId);
            $note->update($attrs);

            return $note;
        }

        return CampaignNote::create($attrs + ['campaign_id' => $campaign->id, 'author_id' => $actor->id]);
    }

    public function deleteNote(Campaign $campaign, User $actor, int $noteId): void
    {
        $this->note($campaign, $actor, $noteId)->delete();
    }

    // ----------------------------------------------------------- ayudantes

    private function note(Campaign $campaign, User $actor, int $noteId): CampaignNote
    {
        $note = CampaignNote::where('campaign_id', $campaign->id)->find($noteId)
            ?? throw new CampaignException('Esa nota no es de esta mesa.');

        // Cada uno edita las suyas; el DJ, todas.
        if ($note->author_id !== $actor->id && $campaign->gm_id !== $actor->id) {
            throw new CampaignException('Solo puedes cambiar tus notas.');
        }

        return $note;
    }

    private function mustBeGm(Campaign $campaign, User $actor): void
    {
        if ($campaign->gm_id !== $actor->id) {
            throw new CampaignException('Eso solo puede hacerlo el DJ.');
        }
    }

    private function mustOwnOrGm(Campaign $campaign, User $actor, Sheet $sheet): void
    {
        if ($sheet->owner_id !== $actor->id && $campaign->gm_id !== $actor->id) {
            throw new CampaignException('Esa hoja no es tuya.');
        }

        if (! $campaign->sheets()->whereKey($sheet->id)->exists()) {
            throw new CampaignException('Esa hoja no está en la mesa.');
        }
    }

    private function member(Campaign $campaign, int $userId): CampaignMember
    {
        return CampaignMember::where('campaign_id', $campaign->id)->where('user_id', $userId)->first()
            ?? throw new CampaignException('Esa persona no está en la mesa.');
    }

    private function shareLevel(string $level): void
    {
        if (! in_array($level, self::SHARE_LEVELS, true)) {
            throw new CampaignException('Nivel de compartición no válido.');
        }
    }

    /** Una plantilla publicada que el DJ pueda ver, o ninguna. */
    private function usableTemplate(User $user, ?int $templateId): ?int
    {
        if (! $templateId) {
            return null;
        }

        $template = Template::whereKey($templateId)->whereNotNull('current_version_id')->first();

        return $template && $user->can('view', $template) ? $template->id : null;
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
