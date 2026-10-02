<?php

namespace App\Policies;

use App\Models\Template;
use App\Models\User;

/**
 * Toda comprobación de permisos sobre plantillas pasa por aquí. Nada de checks
 * sueltos repartidos por los componentes.
 */
class TemplatePolicy
{
    public function viewAny(?User $user): bool
    {
        return true; // el catálogo público es visible para cualquiera
    }

    public function view(?User $user, Template $template): bool
    {
        if ($template->visibility === 'public' || $template->visibility === 'unlisted') {
            return true;
        }

        return $user !== null && $user->id === $template->owner_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Template $template): bool
    {
        return $user->id === $template->owner_id;
    }

    public function delete(User $user, Template $template): bool
    {
        return $user->id === $template->owner_id && ! $template->is_official;
    }

    public function publish(User $user, Template $template): bool
    {
        return $this->update($user, $template);
    }

    /** Clonar exige poder verla; el resultado pertenece a quien clona. */
    public function fork(User $user, Template $template): bool
    {
        return $this->view($user, $template);
    }
}
