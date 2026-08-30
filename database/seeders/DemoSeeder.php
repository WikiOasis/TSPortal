<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CaseComment;
use App\Models\CheckUserCheck;
use App\Models\DataRemoval;
use App\Models\Investigation;
use App\Models\InvestigationNote;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subject;
use App\Models\User;
use App\Models\Wiki;
use App\Services\Safety\References;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (SafetyCase::query()->exists()) {
            $this->command?->warn('There are already cases here. Nothing seeded.');

            return;
        }

        $staff = User::query()->firstOrCreate(
            ['mw_central_id' => 900001],
            [
                'username' => 'M.Okonjo',
                'real_name' => 'Miriam Okonjo',
                'flags' => ['ts', 'user-manager', 'admin'],
                'granted_flags' => ['ts', 'user-manager', 'admin'],
                'active' => true,
            ],
        );

        $reporter = Subject::forUsername('Halcyon Reed', 42);
        $reporter->forceFill(['registered_at' => '2019-03-02 00:00:00'])->save();

        $spammer = Subject::forUsername('Bright Kettle Media', 43);
        $harasser = Subject::forUsername('Quiet Marlin', 44);

        $waiting = SafetyCase::create([
            'reference' => 'TS-2026-0481',
            'type' => SafetyCase::TYPE_REPORT,
            'flow' => 'report',
            'wiki' => 'oasis.example',
            'subject_line' => 'Harassment across several talk pages',
            'summary' => 'Repeated personal comments after being asked to stop, on three article talk pages and my own user talk page.',
            'status' => SafetyCase::STATUS_IN_REVIEW,
            'reporter_subject_id' => $reporter->id,
            'about' => ['User:Quiet Marlin', 'Talk:Pumice'],
            'answers' => [
                'help' => 'unacceptable',
                'users' => ['User:Quiet Marlin'],
                'pages' => ['Talk:Pumice', 'Talk:Basalt'],
                'details' => 'Four diffs, all after I asked them to stop on 2 July.',
            ],
            'created_at' => now()->subDays(39),
            'updated_at' => now()->subDays(11),
        ]);
        $waiting->subjects()->attach($harasser->id, ['role' => 'reported']);

        CaseComment::create([
            'case_id' => $waiting->id,
            'author_type' => CaseComment::AUTHOR_SUBJECT,
            'author_subject_id' => $reporter->id,
            'author_label' => $reporter->username,
            'body' => 'Filed with links to the four diffs listed above.',
            'visibility' => CaseComment::VISIBILITY_PUBLIC,
            'created_at' => now()->subDays(39),
        ]);
        CaseComment::create([
            'case_id' => $waiting->id,
            'author_type' => CaseComment::AUTHOR_STAFF,
            'author_user_id' => $staff->id,
            'author_label' => $staff->publicLabel(),
            'body' => 'Thank you for the report. We have it and are reading through the diffs. You do not need to do anything further for now; we will ask here if we need more.',
            'visibility' => CaseComment::VISIBILITY_PUBLIC,
            'created_at' => now()->subDays(35),
        ]);
        CaseComment::create([
            'case_id' => $waiting->id,
            'author_type' => CaseComment::AUTHOR_STAFF,
            'author_user_id' => $staff->id,
            'author_label' => $staff->publicLabel(),
            'body' => 'Checked the CU log: the two accounts are unrelated. Do not repeat any of this to the reporter.',
            'visibility' => CaseComment::VISIBILITY_INTERNAL,
            'created_at' => now()->subDays(35),
        ]);

        $file = Investigation::create([
            'reference' => 'TS-2026-0483',
            'title' => 'Sustained harassment of Halcyon Reed',
            'premise' => 'Two reports a fortnight apart naming the same account. Worth reading together rather than answering separately.',
            'status' => Investigation::STATUS_OPEN,
            'priority' => 'high',
            'opened_by' => $staff->id,
            'assigned_to' => $staff->id,
            'opened_at' => now()->subDays(34),
            'review_at' => now()->subDays(2),
            'created_at' => now()->subDays(34),
            'updated_at' => now()->subDays(9),
        ]);
        References::adopt($file, $file->title);

        $file->subjects()->attach($harasser->id, ['role' => 'subject', 'created_at' => now(), 'updated_at' => now()]);
        $file->subjects()->attach($reporter->id, ['role' => 'reporter', 'created_at' => now(), 'updated_at' => now()]);

        $waiting->forceFill([
            'investigation_id' => $file->id,
            'status' => SafetyCase::STATUS_INVESTIGATING,
        ])->save();

        InvestigationNote::create([
            'investigation_id' => $file->id,
            'author_id' => $staff->id,
            'kind' => InvestigationNote::KIND_FINDING,
            'body' => 'Four diffs check out. Two are borderline; two are plainly personal. The pattern is after a request to stop, which is the part that matters.',
            'created_at' => now()->subDays(30),
            'updated_at' => now()->subDays(30),
        ]);
        InvestigationNote::create([
            'investigation_id' => $file->id,
            'author_id' => $staff->id,
            'kind' => InvestigationNote::KIND_CONTACT,
            'body' => 'Spoke to the wiki\'s admins. They have tried talk-page warnings twice. They would rather this was handled centrally.',
            'created_at' => now()->subDays(21),
            'updated_at' => now()->subDays(21),
        ]);
        InvestigationNote::create([
            'investigation_id' => $file->id,
            'author_id' => $staff->id,
            'kind' => InvestigationNote::KIND_DECISION,
            'body' => 'Interaction ban rather than a block: the editing itself is fine, and a block would take a productive editor off a small wiki over conduct towards one person.',
            'created_at' => now()->subDays(9),
            'updated_at' => now()->subDays(9),
        ]);

        $watch = Investigation::create([
            'reference' => 'TS-2026-0490',
            'title' => 'Cross-wiki account creation pattern',
            'premise' => 'Noticed while reading something else: eleven accounts created within an hour across four wikis, all editing the same article. Nobody has reported it.',
            'status' => Investigation::STATUS_MONITORING,
            'priority' => 'normal',
            'opened_by' => $staff->id,
            'assigned_to' => $staff->id,
            'opened_at' => now()->subDays(12),
            'review_at' => now()->addDays(9),
            'created_at' => now()->subDays(12),
            'updated_at' => now()->subDays(12),
        ]);
        References::adopt($watch, $watch->title);
        $watch->subjects()->attach(
            Subject::forUsername('Pumice Fan 2026')->id,
            ['role' => 'subject', 'created_at' => now(), 'updated_at' => now()],
        );

        $acted = SafetyCase::create([
            'reference' => 'TS-2026-0512',
            'type' => SafetyCase::TYPE_REPORT,
            'flow' => 'report',
            'wiki' => 'oasis.example',
            'subject_line' => 'Spam links added by a new account',
            'summary' => 'Twelve outbound links to the same shop added across unrelated articles.',
            'status' => SafetyCase::STATUS_ACTION_TAKEN,
            'reporter_subject_id' => $reporter->id,
            'assigned_to' => $staff->id,
            'about' => ['User:Bright Kettle Media'],
            'created_at' => now()->subDays(19),
            'updated_at' => now()->subDays(17),
        ]);
        $acted->subjects()->attach($spammer->id, ['role' => 'reported']);

        $spamFile = Investigation::create([
            'reference' => 'TS-2026-0513',
            'title' => 'Link spam from Bright Kettle Media',
            'premise' => 'Twelve outbound links to one shop across unrelated articles, after a warning.',
            'status' => Investigation::STATUS_CONCLUDED,
            'priority' => 'normal',
            'outcome' => Investigation::OUTCOME_SUSPENDED,
            'outcome_disclosable' => true,
            'findings' => 'Third account from the same range this month. Suspended; the range is worth watching.',
            'opened_by' => $staff->id,
            'assigned_to' => $staff->id,
            'closed_by' => $staff->id,
            'opened_at' => now()->subDays(19),
            'closed_at' => now()->subDays(17),
            'created_at' => now()->subDays(19),
            'updated_at' => now()->subDays(17),
        ]);
        References::adopt($spamFile, $spamFile->title);
        $spamFile->subjects()->attach($spammer->id, ['role' => 'subject', 'created_at' => now(), 'updated_at' => now()]);
        $acted->forceFill(['investigation_id' => $spamFile->id])->save();

        Sanction::create([
            'reference' => 'AC-2026-0119',
            'subject_id' => $spammer->id,
            'case_id' => $acted->id,
            'investigation_id' => $spamFile->id,
            'type' => Sanction::TYPE_LOCK,
            'label' => Sanction::LABELS[Sanction::TYPE_LOCK],
            'scope' => 'WikiOasis (all projects)',
            'reason' => 'Twelve outbound links to a single shop across unrelated articles, continuing after a warning.',
            'internal_reason' => 'Third account from the same range this month.',
            'issued_at' => now()->subDays(17),
            'active' => true,
            'appealable' => true,
            'issued_by' => $staff->id,
            'push_state' => Sanction::PUSH_PUSHED,
            'pushed_at' => now()->subDays(17),
        ]);
        $spammer->refreshStanding();

        Sanction::create([
            'reference' => 'AC-2025-0842',
            'subject_id' => $harasser->id,
            'investigation_id' => $file->id,
            'type' => Sanction::TYPE_BLOCK,
            'label' => Sanction::LABELS[Sanction::TYPE_BLOCK],
            'wikis' => ['oasisexamplewiki', 'pumicewiki'],
            'reason' => 'Continued personal comments about another editor after being asked to stop.',
            'issued_at' => now()->subMonths(2),
            'expires_at' => now()->addMonths(4),
            'active' => true,
            'appealable' => true,
            'issued_by' => $staff->id,
            'push_state' => Sanction::PUSH_PARTIAL,
            'push_error' => 'The wiki recorded this but did not confirm the block on pumicewiki.',
        ]);
        $harasser->refreshStanding();

        $spammer->forceFill(['email' => 'brightkettle@example.org'])->save();

        SafetyCase::create([
            'reference' => 'AP-2026-0001',
            'type' => SafetyCase::TYPE_APPEAL,
            'flow' => 'appeal',
            'subject_line' => 'Appeal against Account suspension (AC-2026-0119)',
            'summary' => 'The links were to my own site and I did not know that was against the rules. I have read the guideline now.',
            'status' => SafetyCase::STATUS_RECEIVED,
            'reporter_subject_id' => $spammer->id,
            'sanction_id' => Sanction::query()->where('reference', 'AC-2026-0119')->value('id'),

            'appeal_link_source' => 'only-action',
            'appeal_link_confidence' => 'likely',
            'appeal_link_notes' => [
                'explanation' => 'This is the only action on their file, so there is nothing else it could be about.',
                'notes' => [],
                'considered' => [],
            ],
            'answers' => ['authenticated' => false],
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        SafetyCase::create([
            'reference' => 'AP-2025-0114',
            'type' => SafetyCase::TYPE_APPEAL,
            'flow' => 'appeal',
            'subject_line' => 'Appeal against Block (AC-2025-0842)',
            'summary' => 'I was not the one making those edits and I would like the block reviewed.',
            'status' => SafetyCase::STATUS_REJECTED,
            'reporter_subject_id' => $harasser->id,
            'sanction_id' => Sanction::query()->where('reference', 'AC-2025-0842')->value('id'),
            'appeal_link_source' => 'staff',
            'appeal_link_confidence' => 'certain',
            'appeal_link_notes' => ['explanation' => 'Set by hand.', 'notes' => [], 'considered' => []],
            'appeal_outcome' => SafetyCase::APPEAL_DECLINED,
            'appeal_outcome_note' => 'We have looked at this again with the checkuser evidence and the '
                .'block stands. You can appeal again in three months.',
            'appeal_decided_by' => $staff->id,
            'appeal_decided_at' => now()->subDays(9),
            'closed_at' => now()->subDays(9),
            'answers' => ['authenticated' => true],
            'created_at' => now()->subDays(14),
            'updated_at' => now()->subDays(9),
        ]);

        $leaving = Subject::forUsername('Ordinary Sandpiper', 45);
        $leaving->forceFill(['email' => 'sandpiper@example.org'])->save();

        $dataCase = SafetyCase::create([
            'reference' => 'TS-2026-0520',
            'type' => SafetyCase::TYPE_DATA,
            'flow' => 'manage-my-data',
            'wiki' => 'oasis.example',
            'subject_line' => 'Please delete my account and everything about me',
            'summary' => 'I would like my account and all my personal information removed. I do not edit any more.',
            'status' => SafetyCase::STATUS_IN_REVIEW,
            'data_kind' => SafetyCase::DATA_ERASURE,
            'reporter_subject_id' => $leaving->id,
            'assigned_to' => $staff->id,
            'created_at' => now()->subDays(4),
            'updated_at' => now()->subDays(3),
        ]);

        DataRemoval::create([
            'reference' => 'TS-2026-0521',
            'subject_id' => $leaving->id,
            'case_id' => $dataCase->id,
            'target_username' => DataRemoval::usernameFor(),
            'previous_username' => $leaving->username,
            'state' => DataRemoval::STATE_REQUESTED,
            'legal_basis' => 'gdpr-17',
            'reason' => 'Asked for erasure under Article 17. No open reports naming them and nothing on file.',
            'requested_by' => $staff->id,
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);

        $curious = Subject::forUsername('Careful Wren', 46);
        $curious->forceFill(['email' => 'wren@example.org'])->save();

        SafetyCase::create([
            'reference' => 'TS-2026-0522',
            'type' => SafetyCase::TYPE_DATA,
            'flow' => 'manage-my-data',
            'wiki' => 'oasis.example',
            'subject_line' => 'Please send me a copy of what you hold about me',
            'summary' => 'I would like to see everything Trust & Safety has about my account.',
            'status' => SafetyCase::STATUS_RECEIVED,
            'data_kind' => null,
            'reporter_subject_id' => $curious->id,
            'created_at' => now()->subDays(1),
            'updated_at' => now()->subDays(1),
        ]);

        foreach ([
            ['dbname' => 'oasisexamplewiki', 'sitename' => 'Oasis Example', 'url' => 'https://oasis.example'],
            ['dbname' => 'pumicewiki', 'sitename' => 'Pumice Wiki', 'url' => 'https://pumice.example'],
        ] as $wiki) {
            Wiki::query()->updateOrCreate(
                ['dbname' => $wiki['dbname']],
                $wiki + ['last_seen_at' => now()],
            );
        }

        $this->categorise();
        $this->checkUserLog();

        foreach (SafetyCase::all() as $case) {
            References::adopt($case, $case->subject_line);
        }
        foreach (Sanction::all() as $sanction) {
            References::adopt($sanction, $sanction->label.': '.$sanction->subject->username);
        }
        foreach (DataRemoval::all() as $removal) {
            References::adopt($removal, 'Data removal: '.$removal->previous_username);
        }

        $this->command?->info(
            'Seeded 4 cases, 3 investigations, 2 actions, 1 erasure awaiting approval, '
            .'18 CheckUser checks and 1 staff account (M.Okonjo, central id 900001).'
        );
    }

    private function categorise(): void
    {
        $filings = [
            'TS-2026-0481' => [
                ['harassment', 'Harassment', 'conduct'],
                ['doxxing', 'Disclosure of personal information', 'privacy'],
            ],
            'TS-2026-0512' => [['spam', 'Spam or advertising', 'integrity']],
            'TS-2026-0520' => [['erasure', 'Erasure', 'data']],
        ];

        foreach ($filings as $reference => $categories) {
            $case = SafetyCase::query()->where('reference', $reference)->first();
            if ($case === null) {
                continue;
            }

            foreach ($categories as $index => [$id, $label, $group]) {
                $case->categories()->create([
                    'category' => $id,
                    'label' => $label,
                    'group' => $group,
                    'source_field' => 'report',
                    'is_primary' => $index === 0,
                ]);
            }

            $case->forceFill([
                'category' => $categories[0][0],
                'category_group' => $categories[0][2],
            ])->save();
        }

        Sanction::query()->where('type', Sanction::TYPE_LOCK)
            ->update(['reason_category' => 'spam']);
        Sanction::query()->where('type', Sanction::TYPE_BLOCK)
            ->update(['reason_category' => 'harassment']);
    }

    private function checkUserLog(): void
    {
        $checkers = [
            ['Marisol Kane', 900002, true],
            ['Ptarmigan', 900003, false],
        ];

        $reasons = [
            'Cross-wiki sockpuppetry, see TS-2026-0481',
            'Checking for block evasion after AC-2026-0119',
            'Range check requested by the local administrators',
        ];

        $log = 0;

        foreach ($checkers as [$name, $centralId, $diligent]) {
            foreach (range(0, 8) as $i) {
                $log++;
                $explained = $diligent || $i % 3 !== 0;

                CheckUserCheck::create([
                    'wiki' => $i % 4 === 0 ? 'oasis.example' : 'oasiswiki',
                    'log_id' => $log,
                    'checked_at' => now()->subDays(14 - $i)->setTime(9 + $i % 6, 15),
                    'checker_username' => $name,
                    'checker_central_id' => $centralId,
                    'type' => $i % 3 === 0 ? 'userips' : ($i % 3 === 1 ? 'ipedits' : 'ipusers'),
                    'target_kind' => $i % 3 === 0 ? 'account' : 'ip',
                    'target_name' => $i % 3 === 0 ? 'Quiet Marlin' : null,
                    'target_fingerprint' => substr(hash('sha256', 'demo-target-'.($i % 4)), 0, 16),
                    'reason' => $explained ? $reasons[$i % 3] : null,
                    'reason_given' => $explained,
                    'received_at' => now()->subDays(14 - $i),
                ]);
            }
        }
    }
}
