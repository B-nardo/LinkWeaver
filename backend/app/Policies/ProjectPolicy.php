<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Projects are private to their owner, with one exception: the seeded demo
 * project is world-readable so the landing page can show real data without
 * anyone signing up.
 */
final class ProjectPolicy
{
    public function view(?User $user, Project $project): bool
    {
        if ($project->is_demo) {
            return true;
        }

        return $user !== null && $user->id === $project->user_id;
    }

    public function update(User $user, Project $project): bool
    {
        return ! $project->is_demo && $user->id === $project->user_id;
    }

    /**
     * The demo project is never deletable: it is shared infrastructure, not
     * the signed-in user's to remove.
     */
    public function delete(User $user, Project $project): bool
    {
        return ! $project->is_demo && $user->id === $project->user_id;
    }
}
