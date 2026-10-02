<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;

class CampaignPolicy
{
    public function view(User $user, Campaign $campaign): bool
    {
        return $campaign->roleOf($user) !== null;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Campaign $campaign): bool
    {
        return $user->id === $campaign->gm_id;
    }

    public function delete(User $user, Campaign $campaign): bool
    {
        return $user->id === $campaign->gm_id;
    }

    /** Expulsar miembros, cambiar roles, ver tiradas privadas. */
    public function manage(User $user, Campaign $campaign): bool
    {
        return $user->id === $campaign->gm_id;
    }

    public function join(User $user, Campaign $campaign): bool
    {
        return ! $campaign->is_archived && $campaign->roleOf($user) === null;
    }
}
