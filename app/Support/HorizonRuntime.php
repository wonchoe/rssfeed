<?php

namespace App\Support;

use Laravel\Horizon\Contracts\SupervisorRepository;
use Throwable;

class HorizonRuntime
{
    public function supervisorsCount(): ?int
    {
        try {
            return count(app(SupervisorRepository::class)->all());
        } catch (Throwable) {
            return null;
        }
    }

    public function hasActiveSupervisors(): ?bool
    {
        $count = $this->supervisorsCount();

        return $count === null ? null : $count > 0;
    }

    public function shouldRunSyncFallback(): bool
    {
        return app()->environment('local') && $this->hasActiveSupervisors() === false;
    }
}
