<?php

namespace App\Domain\Catalog;

use App\Domain\Transfer\TemplateTransfer;
use App\Models\Template;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo público de plantillas (Fase 7): buscar, me gusta, instalaciones,
 * clonar y reportar.
 *
 * En el catálogo solo están las plantillas PÚBLICAS y PUBLICADAS. Una
 * «oculta» (unlisted) se puede abrir por su enlace, pero no aparece aquí.
 */
final class Catalog
{
    public const SORTS = ['popular' => 'Más usadas', 'likes' => 'Más gustadas', 'recent' => 'Recientes', 'name' => 'Nombre'];

    public const REPORT_REASONS = [
        'copyright' => 'Copia texto o material con derechos',
        'offensive' => 'Contenido ofensivo',
        'spam' => 'Spam o engañosa',
        'other' => 'Otro motivo',
    ];

    /**
     * Búsqueda sin distinguir mayúsculas en nombre, lema, línea y
     * descripción. lower() a los dos lados funciona igual en PostgreSQL y en
     * SQLite (§3.1: PostgreSQL distingue mayúsculas al comparar texto).
     */
    public function search(string $query = '', string $line = '', string $sort = 'popular'): Builder
    {
        $q = Template::query()
            ->public()
            ->whereNotNull('current_version_id')
            ->with(['owner:id,name', 'currentVersion:id,template_id,version,published_at', 'forkedFrom:id,name,slug']);

        $query = trim(mb_strtolower($query));
        if ($query !== '') {
            $like = '%'.addcslashes($query, '%_\\').'%';
            $q->where(function ($w) use ($like) {
                foreach (['name', 'tagline', 'game_line', 'description'] as $column) {
                    $w->orWhereRaw("lower({$column}) like ?", [$like]);
                }
            });
        }

        if ($line !== '') {
            $q->where('game_line', $line);
        }

        return match ($sort) {
            'likes' => $q->orderByDesc('likes_count')->orderByDesc('installs_count'),
            'recent' => $q->orderByDesc('updated_at'),
            'name' => $q->orderBy('name'),
            default => $q->orderByDesc('is_official')->orderByDesc('installs_count')->orderByDesc('likes_count'),
        };
    }

    /** @return array<string,int> línea de juego => cuántas plantillas */
    public function gameLines(): array
    {
        return Template::public()->whereNotNull('current_version_id')->whereNotNull('game_line')
            ->groupBy('game_line')->orderBy('game_line')
            ->pluck(DB::raw('count(*) as n'), 'game_line')->map(fn ($n) => (int) $n)->all();
    }

    public function likes(User $user, Template $template): bool
    {
        return DB::table('template_likes')->where('template_id', $template->id)->where('user_id', $user->id)->exists();
    }

    /** @return bool si ahora le gusta */
    public function toggleLike(User $user, Template $template): bool
    {
        $this->mustSee($user, $template);

        return DB::transaction(function () use ($user, $template) {
            $row = DB::table('template_likes')->where('template_id', $template->id)->where('user_id', $user->id);
            $liked = ! $row->exists();

            $liked
                ? DB::table('template_likes')->insert(['template_id' => $template->id, 'user_id' => $user->id, 'created_at' => now()])
                : $row->delete();

            // El contador se recuenta: con dos clics a la vez, un ±1 se descuadraría.
            $template->forceFill(['likes_count' => DB::table('template_likes')->where('template_id', $template->id)->count()])->saveQuietly();

            return $liked;
        });
    }

    /**
     * Una instalación es la primera vez que alguien crea una hoja con una
     * plantilla ajena. Lo llama CreateSheet.
     */
    public function recordInstall(User $user, Template $template): void
    {
        if ($user->id === $template->owner_id) {
            return;
        }

        $already = DB::table('template_installs')->where('template_id', $template->id)->where('user_id', $user->id)->exists();

        if (! $already) {
            DB::table('template_installs')->insert(['template_id' => $template->id, 'user_id' => $user->id, 'created_at' => now()]);
            $template->forceFill(['installs_count' => DB::table('template_installs')->where('template_id', $template->id)->distinct()->count('user_id')])->saveQuietly();
        }
    }

    /**
     * Clonar (fork): una copia privada, sin publicar, de la versión publicada,
     * para modificarla. Recuerda de dónde viene (forked_from_id).
     *
     * @return array{0: Template, 1: array<int,string>}
     */
    public function fork(User $user, Template $template): array
    {
        $this->mustSee($user, $template);

        $json = app(TemplateTransfer::class)->exportPublished($template);
        $json['template']['name'] = mb_substr($template->name.' (copia)', 0, 120);

        [$copy, $warnings] = app(TemplateTransfer::class)->import($user, $json);
        $copy->forceFill(['forked_from_id' => $template->id, 'is_official' => false])->save();

        return [$copy->fresh(), $warnings];
    }

    public function report(User $user, Template $template, string $reason, ?string $details): void
    {
        $this->mustSee($user, $template);

        if (! array_key_exists($reason, self::REPORT_REASONS)) {
            throw new CatalogException('Elige un motivo.');
        }

        if ($user->id === $template->owner_id) {
            throw new CatalogException('No puedes reportar tu propia plantilla.');
        }

        DB::table('template_reports')->updateOrInsert(
            ['template_id' => $template->id, 'user_id' => $user->id],
            ['reason' => $reason, 'details' => mb_substr(trim((string) $details), 0, 2000) ?: null, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    private function mustSee(User $user, Template $template): void
    {
        if (! $user->can('view', $template) || ! $template->isPublished()) {
            throw new CatalogException('Esa plantilla no está disponible.');
        }
    }
}
