<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Models\CaseComment;
use App\Models\SafetyCase;
use App\Models\Subject;
use Illuminate\Console\Command;

class ListRetentionDue extends Command
{
    protected $signature = 'tsportal:retention
                            {--years=0 : How long after an erasure records may be kept}
                            {--days=0 : The same, in days, for a policy that counts them}
                            {--limit=100 : How many accounts to list}';

    protected $description = 'List erased accounts whose portal records are past retention';

    public function handle(): int
    {
        $years = (int) $this->option('years');
        $days = (int) $this->option('days');

        if ($years <= 0 && $days <= 0) {
            $this->error('Say how long records may be kept: --years=3, or --days=1095.');
            $this->line('There is no default on purpose. A retention period is a policy');
            $this->line('decision, and a command that guessed at one would be making it.');

            return self::FAILURE;
        }

        $cutoff = now()->subYears(max(0, $years))->subDays(max(0, $days));

        $subjects = Subject::query()
            ->erasedBefore($cutoff)
            ->orderBy('erased_at')
            ->limit((int) $this->option('limit'))
            ->get();

        $this->newLine();
        $this->line(sprintf(
            'Erased on or before %s — %d account(s):',
            $cutoff->toDateString(),
            $subjects->count(),
        ));
        $this->newLine();

        if ($subjects->isEmpty()) {
            $this->info('Nothing is past retention.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($subjects as $subject) {
            $caseIds = SafetyCase::query()
                ->where('reporter_subject_id', $subject->id)
                ->pluck('id');

            $rows[] = [
                $subject->id,
                $subject->username,
                $subject->wiki_username ?? '—',
                $subject->erased_at?->toDateString() ?? '—',
                $caseIds->count(),
                CaseComment::query()->whereIn('case_id', $caseIds)->count(),
                Attachment::query()->whereIn('case_id', $caseIds)->whereNotNull('path')->count(),
            ];
        }

        $this->table(
            ['Subject', 'Retained name', 'On the wiki', 'Erased', 'Cases', 'Comments', 'Files'],
            $rows,
        );

        $this->newLine();
        $this->warn('Nothing has been deleted. This is a list, not an action.');
        $this->line('Check each one before removing it: an account erased years ago may still be');
        $this->line('named in an open investigation, in an action still in force, or in a case');
        $this->line('somebody has asked us about. None of that is visible to this command.');
        $this->newLine();
        $this->line('Files have to go through the disk as well as the database — see');
        $this->line('App\Services\Safety\AttachmentStore::forget(), which removes the bytes and');
        $this->line('keeps the row saying a file was here.');

        return self::SUCCESS;
    }
}
