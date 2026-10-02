<?php

namespace App\Domain\Schema;

use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Publicar = congelar el borrador en una versión inmutable.
 *
 * A partir de aquí, seguir editando la plantilla no afecta a las hojas ya
 * creadas: siguen apuntando a la versión con la que nacieron hasta que su
 * dueño decida migrarlas.
 */
final class PublishTemplate
{
    public function __construct(private SchemaCompiler $compiler = new SchemaCompiler) {}

    /**
     * @throws SchemaCompilationException si el borrador no compila
     */
    public function __invoke(
        Template $template,
        ?User $author = null,
        ?string $label = null,
        ?string $changelog = null,
    ): TemplateVersion {
        // Compilar ANTES de abrir la transacción: si el borrador tiene errores
        // no queremos ni haber tocado la base de datos.
        $schema = $this->compiler->compile($template);

        return DB::transaction(function () use ($template, $author, $label, $changelog, $schema) {
            // Bloqueo para que dos publicaciones simultáneas no reclamen el
            // mismo número de versión.
            $template = Template::query()->lockForUpdate()->findOrFail($template->id);

            $version = TemplateVersion::create([
                'template_id' => $template->id,
                'version' => $template->nextVersionNumber(),
                'label' => $label,
                'changelog' => $changelog,
                'compiled_schema' => $schema->toArray(),
                'published_at' => now(),
                'created_by' => $author?->id,
            ]);

            $template->forceFill(['current_version_id' => $version->id])->save();

            return $version;
        });
    }
}
