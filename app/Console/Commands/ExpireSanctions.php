<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Safety\SanctionService;
use Illuminate\Console\Command;

class ExpireSanctions extends Command
{
    protected $signature = 'tsportal:expire-sanctions';

    protected $description = 'Deactivate sanctions whose expiry has passed and tell the wiki';

    public function handle(SanctionService $sanctions): int
    {
        $count = $sanctions->expireDue();

        $this->info($count === 0 ? 'Nothing had expired.' : "Expired {$count} sanction(s).");

        return self::SUCCESS;
    }
}
