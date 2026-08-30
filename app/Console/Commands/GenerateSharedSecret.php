<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateSharedSecret extends Command
{
    protected $signature = 'tsportal:secret';

    protected $description = 'Generate a shared secret for portal ↔ wiki requests';

    public function handle(): int
    {
        $secret = Str::random(64);

        $this->newLine();
        $this->line('  Put this in <options=bold>both</> places, and nowhere else:');
        $this->newLine();
        $this->line('  <comment>.env</> on the portal');
        $this->line("      MW_S2S_SECRET={$secret}");
        $this->newLine();
        $this->line('  <comment>LocalSettings.php</> on every wiki running WikiOasisSafety');
        $this->line("      \$wgWikiOasisSafetyPortalSecret = '{$secret}';");
        $this->newLine();
        $this->warn('  Anyone holding this can file reports and read any account\'s standing. Treat it as a password.');
        $this->newLine();

        return self::SUCCESS;
    }
}
