<?php

namespace App\Support;

use Illuminate\Support\Facades\Redis;
use Throwable;

class HorizonRuntime
{
    public function supervisorsCount(): ?int
    {
        try {
            $prefix = (string) config('horizon.prefix', 'horizon:');
            $supervisors = Redis::smembers($prefix.'supervisors');

            return is_array($supervisors) ? count($supervisors) : 0;
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
