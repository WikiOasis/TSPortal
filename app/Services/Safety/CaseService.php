<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Mail\CaseReceivedMail;
use App\Mail\CaseUpdatedMail;
use App\Models\CaseCategory;
use App\Models\CaseComment;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class CaseService
{
    public function __construct(
        private readonly WikiSync $sync,
        private readonly AttachmentStore $files = new AttachmentStore,
        private readonly AppealParser $appeals = new AppealParser,
    ) {}

    /**
     * @param  array{type: string, flow?: ?string, wiki?: ?string, anonymous?: bool,
     *               reporter?: array{central_id?: ?int, username?: ?string, email?: ?string},
     *               answers?: array<string, mixed>, roles?: array<string, mixed>,
     *               categories?: list<array{id: string, label?: string, group?: ?string, field?: ?string}>,
     *               subject?: ?string, summary?: ?string, sanction_reference?: ?string,
     *               attachments?: list<array<string, mixed>>}  $input
     */
    public function createFromSubmission(array $input): SafetyCase
    {
        $type = in_array($input['type'] ?? '', SafetyCase::TYPES, true)
            ? $input['type']
            : SafetyCase::TYPE_REPORT;

        $anonymous = (bool) ($input['anonymous'] ?? false);
        $answers = (array) ($input['answers'] ?? []);
        $roles = (array) ($input['roles'] ?? []);

        $reporter = null;
        $reporterUsername = Arr::get($input, 'reporter.username');
        if (! $anonymous && is_string($reporterUsername) && $reporterUsername !== '') {
            $reporter = Subject::forUsername($reporterUsername, Arr::get($input, 'reporter.central_id'));

            $email = Arr::get($input, 'reporter.email');
            if (is_string($email) && $email !== '' && ($reporter->email === null || $reporter->email === '')) {
                $reporter->forceFill(['email' => $email])->save();
            }
        }

        $about = $this->aboutFrom($roles, $answers);
        $categories = $this->categoriesFrom($input);

        $type = $this->appealTypeFor($type, $categories);

        $appeal = $this->appealMatch($type, $input, $reporter, $roles, $answers);
        $appealed = $appeal->sanction;

        $subjectLine = $this->subjectLine($input, $type, $roles, $answers);

        $case = DB::transaction(function () use ($type, $anonymous, $input, $answers, $roles, $reporter, $about, $subjectLine, $appeal, $appealed, $categories) {
            $reference = SafetyCase::nextReference($subjectLine);

            $case = SafetyCase::create([
                'reference' => $reference,
                'type' => $type,
                'flow' => $input['flow'] ?? null,
                'subject_line' => $subjectLine,
                'summary' => $this->summary($input, $roles, $answers),
                'status' => SafetyCase::STATUS_RECEIVED,
                'anonymous' => $anonymous,
                'reporter_subject_id' => $reporter?->id,
                'wiki' => $input['wiki'] ?? null,
                'answers' => $answers ?: null,
                'about' => $about ?: null,
                'sanction_id' => $appealed?->id,

                'appeal_link_source' => $type === SafetyCase::TYPE_APPEAL ? $appeal->source : null,
                'appeal_link_confidence' => $type === SafetyCase::TYPE_APPEAL ? $appeal->confidence : null,
                'appeal_link_notes' => $type === SafetyCase::TYPE_APPEAL ? $appeal->toRecord() : null,

                'investigation_id' => $appealed?->investigation_id,
                'category' => $categories[0]['category'] ?? null,
                'category_group' => $categories[0]['group'] ?? null,
                'priority' => Triage::priorityFor(
                    array_column($categories, 'category'),
                    SafetyCase::PRIORITY_NORMAL,
                    $answers,
                ),
                'threat_to_life' => Triage::detect(array_column($categories, 'category'), $answers),
                'data_kind' => $type === SafetyCase::TYPE_DATA
                    ? $this->dataKindFrom($roles, $answers)
                    : null,
            ]);

            References::attach($reference, $case, $subjectLine);

            $this->linkSubjects($case, $roles, $answers);
            $this->writeCategories($case, $categories);

            foreach ((array) ($input['attachments'] ?? []) as $file) {
                if (is_array($file)) {
                    $this->files->record($case, $file);
                }
            }

            return $case;
        });

        Audit::log('case.created', $case, [
            'type' => $case->type,
            'anonymous' => $case->anonymous,
            'wiki' => $case->wiki,
            'categories' => array_column($categories, 'category'),
            'priority' => $case->priority,
            'threat_to_life' => $case->threat_to_life,
            'appeal_against' => $appealed?->reference,
            'appeal_link' => $type === SafetyCase::TYPE_APPEAL ? $appeal->source : null,
        ], actorLabel: 'wiki');

        $this->sync->pushCase($case);
        $this->email($case, fn ($to) => new CaseReceivedMail($case, $to));

        return $case;
    }

    public function comment(
        SafetyCase $case,
        string $body,
        string $visibility = CaseComment::VISIBILITY_INTERNAL,
        ?User $staff = null,
        ?Subject $subject = null,
    ): CaseComment {
        $authorType = match (true) {
            $staff !== null => CaseComment::AUTHOR_STAFF,
            $subject !== null => CaseComment::AUTHOR_SUBJECT,
            default => CaseComment::AUTHOR_SYSTEM,
        };

        $comment = CaseComment::create([
            'case_id' => $case->id,
            'author_type' => $authorType,
            'author_user_id' => $staff?->id,
            'author_subject_id' => $subject?->id,
            'author_label' => $staff?->publicLabel() ?? $subject?->username,
            'body' => $body,
            'visibility' => $visibility,
        ]);

        if ($authorType === CaseComment::AUTHOR_SUBJECT && $case->status === SafetyCase::STATUS_CLOSED) {
            $case->status = SafetyCase::STATUS_IN_REVIEW;
        }
        $case->touch();

        Audit::log('comment.added', $case, [
            'comment_id' => $comment->id,
            'visibility' => $visibility,
            'author' => $authorType,
        ]);

        $this->sync->pushComment($comment);

        if ($comment->isPublic() && $authorType !== CaseComment::AUTHOR_SUBJECT) {
            $this->email($case, fn ($to) => new CaseUpdatedMail($case, $to, $comment));
        }

        return $comment;
    }

    public function setStatus(SafetyCase $case, string $status, ?User $staff = null, ?string $note = null): SafetyCase
    {
        if (! in_array($status, SafetyCase::STATUSES, true)) {
            throw new \InvalidArgumentException("Unknown case status '{$status}'.");
        }

        $from = $case->status;
        if ($from === $status) {
            return $case;
        }

        $case->status = $status;
        $case->closed_at = in_array($status, SafetyCase::CLOSED_STATUSES, true) ? now() : null;
        if ($note !== null) {
            $case->resolution = $note;
        }
        if ($status !== SafetyCase::STATUS_DUPLICATE && $case->duplicate_of_id !== null) {
            $case->forceFill([
                'duplicate_of_id' => null,
                'duplicate_note' => null,
                'duplicate_marked_by' => null,
                'duplicate_marked_at' => null,
            ]);
        }
        $case->save();

        Audit::log('case.status', $case, ['from' => $from, 'to' => $status, 'by' => $staff?->username]);

        $this->recordStatusChange($case, $from, $status, $staff);

        $this->sync->pushCase($case);
        $this->email($case, fn ($to) => new CaseUpdatedMail($case, $to, null, $from));

        return $case;
    }

    private function recordStatusChange(SafetyCase $case, ?string $from, string $to, ?User $staff): void
    {
        $this->comment(
            $case,
            $this->statusSentence($case, $from, $to, $staff),
            $case->anonymous ? CaseComment::VISIBILITY_INTERNAL : CaseComment::VISIBILITY_PUBLIC,
            $staff,
        );
    }

    private function statusSentence(SafetyCase $case, ?string $from, string $to, ?User $staff): string
    {
        $sentence = match ($to) {
            SafetyCase::STATUS_RECEIVED => 'Status changed to received.',
            SafetyCase::STATUS_IN_REVIEW => 'Status changed to in review.',
            SafetyCase::STATUS_INVESTIGATING => 'An investigation was opened. Further updates will follow after the investigation.',
            SafetyCase::STATUS_ACTION_TAKEN => 'Status changed to action taken.',
            SafetyCase::STATUS_CLOSED => 'Status changed to closed.',
            SafetyCase::STATUS_REJECTED => 'Status changed to rejected.',
            SafetyCase::STATUS_DUPLICATE => 'Closed as a duplicate. What was reported here is already being '
                .'dealt with under an earlier report.',
            default => sprintf('Status changed to %s.', str_replace('-', ' ', strtolower($to))),
        };

        return $staff !== null
            ? sprintf('%s — %s.', rtrim($sentence, '.'), $staff->publicLabel())
            : $sentence;
    }

    public function assign(SafetyCase $case, ?User $assignee): SafetyCase
    {
        if ($case->assigned_to === $assignee?->id) {
            return $case;
        }

        $case->assigned_to = $assignee?->id;
        $case->save();

        Audit::log('case.assigned', $case, ['assignee' => $assignee?->username]);

        return $case;
    }

    public function setPriority(SafetyCase $case, string $priority): SafetyCase
    {
        if (! in_array($priority, SafetyCase::PRIORITIES, true)) {
            throw new \InvalidArgumentException("Unknown case priority '{$priority}'.");
        }

        $from = $case->priority;
        if ($from === $priority) {
            return $case;
        }

        $case->priority = $priority;
        $case->save();

        Audit::log('case.priority', $case, [
            'from' => $from,
            'to' => $priority,
            'threat_to_life' => $case->isThreatToLife(),
        ]);

        return $case;
    }

    /**
     * @param  callable(string): Mailable  $build
     */
    private function email(SafetyCase $case, callable $build): void
    {
        $case->loadMissing('reporter');
        $to = $case->reporter?->email;

        if ($case->anonymous || $to === null || $to === '') {
            return;
        }

        Mail::to($to)->queue($build($to));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array{category: string, label: string, group: ?string, source_field: ?string}>
     */
    private function categoriesFrom(array $input): array
    {
        $clean = [];

        foreach ((array) ($input['categories'] ?? []) as $category) {
            if (! is_array($category)) {
                continue;
            }

            $id = $category['id'] ?? null;
            if (! is_string($id) || trim($id) === '') {
                continue;
            }

            $id = CaseCategory::canonical($id);

            if (isset($clean[$id])) {
                continue;
            }

            $label = $category['label'] ?? null;

            $clean[$id] = [
                'category' => $id,
                'label' => is_string($label) && trim($label) !== '' ? Str::limit(trim($label), 250, '') : $id,
                'group' => isset($category['group']) && is_string($category['group']) && $category['group'] !== ''
                    ? $category['group']
                    : null,
                'source_field' => isset($category['field']) && is_string($category['field'])
                    ? Str::limit($category['field'], 64, '')
                    : null,
            ];
        }

        return array_values($clean);
    }

    /**
     * @param  list<array{category: string, label: string, group: ?string, source_field: ?string}>  $categories
     */
    private function writeCategories(SafetyCase $case, array $categories): void
    {
        $case->categories()->delete();

        foreach ($categories as $index => $category) {
            CaseCategory::create($category + [
                'case_id' => $case->id,
                'is_primary' => $index === 0,
            ]);
        }
    }

    /**
     * @param  list<array{id: string, label?: ?string, group?: ?string}>  $categories
     */
    public function categorise(SafetyCase $case, array $categories, ?User $staff = null): SafetyCase
    {
        $before = $case->categories()->pluck('category')->all();
        $resolved = $this->categoriesFrom(['categories' => $categories]);
        $ids = array_column($resolved, 'category');

        $answers = (array) ($case->answers ?? []);

        $priorityBefore = $case->priority;
        $wasThreat = (bool) $case->threat_to_life;
        $isThreat = Triage::detect($ids, $answers);

        $priorityAfter = $wasThreat
            ? $priorityBefore
            : Triage::priorityFor($ids, $priorityBefore, $answers);

        DB::transaction(function () use ($case, $resolved, $priorityAfter, $isThreat, $wasThreat) {
            $this->writeCategories($case, $resolved);

            $case->category = $resolved[0]['category'] ?? null;
            $case->category_group = $resolved[0]['group'] ?? null;
            $case->priority = $priorityAfter;
            $case->threat_to_life = $wasThreat || $isThreat;
            $case->save();
        });

        Audit::log('case.categorised', $case, [
            'from' => $before,
            'to' => $ids,
            'by' => $staff?->username,
            'threat_to_life' => $case->threat_to_life,
        ]);

        if ($priorityAfter !== $priorityBefore) {
            Audit::log('case.escalated', $case, [
                'from' => $priorityBefore,
                'to' => $priorityAfter,
                'reason' => 'threat-to-life',
                'categories' => $ids,
            ]);
        }

        return $case->refresh();
    }

    /**
     * @param  array<string, mixed>  $roles
     * @param  array<string, mixed>  $answers
     * @return list<string>
     */
    private function aboutFrom(array $roles, array $answers): array
    {
        $about = [];

        foreach (['users', 'pages', 'wikis'] as $role) {
            $field = $roles[$role] ?? null;
            if (! is_string($field)) {
                continue;
            }
            foreach ((array) ($answers[$field] ?? []) as $value) {
                if (is_scalar($value) && (string) $value !== '') {
                    $about[] = (string) $value;
                }
            }
        }

        return array_values(array_unique($about));
    }

    /**
     * @param  array<string, mixed>  $roles
     * @param  array<string, mixed>  $answers
     */
    private function linkSubjects(SafetyCase $case, array $roles, array $answers): void
    {
        $field = $roles['users'] ?? null;
        if (! is_string($field)) {
            return;
        }

        foreach ((array) ($answers[$field] ?? []) as $name) {
            if (! is_string($name) || trim($name) === '') {
                continue;
            }
            $name = preg_replace('/^\s*(User|User talk)\s*:\s*/iu', '', $name) ?? $name;
            $subject = Subject::forUsername($name);
            $case->subjects()->syncWithoutDetaching([$subject->id => ['role' => 'reported']]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $roles
     * @param  array<string, mixed>  $answers
     */
    private function subjectLine(array $input, string $type, array $roles, array $answers): string
    {
        $given = $input['subject'] ?? null;
        if (is_string($given) && trim($given) !== '') {
            return Str::limit(trim($given), 200, '');
        }

        $about = $this->aboutFrom($roles, $answers);
        $noun = match ($type) {
            SafetyCase::TYPE_APPEAL => 'Appeal',
            SafetyCase::TYPE_DATA => 'Data request',
            SafetyCase::TYPE_CONTACT => 'Message to Trust & Safety',
            default => 'Report',
        };

        return $about !== []
            ? sprintf('%s concerning %s', $noun, implode(', ', array_slice($about, 0, 3)))
            : $noun;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $roles
     * @param  array<string, mixed>  $answers
     */
    private function summary(array $input, array $roles, array $answers): ?string
    {
        $given = $input['summary'] ?? null;
        if (is_string($given) && trim($given) !== '') {
            return trim($given);
        }

        $field = $roles['details'] ?? null;
        $details = is_string($field) ? ($answers[$field] ?? null) : null;

        return is_string($details) && trim($details) !== '' ? Str::limit(trim($details), 500) : null;
    }

    /**
     * @param  array<string, mixed>  $roles
     * @param  array<string, mixed>  $answers
     */
    private function dataKindFrom(array $roles, array $answers): ?string
    {
        $role = $roles['request_kind'] ?? null;

        $value = is_array($role)
            ? ($role['value'] ?? null)
            : (is_string($role) ? ($answers[$role] ?? null) : null);

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (! is_scalar($value)) {
            return null;
        }

        return SafetyCase::DATA_KIND_SYNONYMS[strtolower(trim((string) $value))] ?? null;
    }

    /**
     * @param  list<array{category: string, label: string, group: ?string, source_field: ?string}>  $categories
     */
    private function appealTypeFor(string $type, array $categories): string
    {
        if ($type === SafetyCase::TYPE_APPEAL || $type === SafetyCase::TYPE_DATA) {
            return $type;
        }

        $ids = (array) config('categories.appeal_categories', []);
        $group = (string) config('categories.appeal_group', 'appeal');

        foreach ($categories as $category) {
            if (in_array($category['category'], $ids, true)) {
                return SafetyCase::TYPE_APPEAL;
            }

            if ($group !== '' && $category['group'] === $group) {
                return SafetyCase::TYPE_APPEAL;
            }
        }

        return $type;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $answers
     */
    private function appealMatch(string $type, array $input, ?Subject $reporter, array $roles, array $answers): AppealMatch
    {
        $stated = $input['sanction_reference'] ?? null;
        $stated = is_string($stated) && trim($stated) !== '' ? trim($stated) : null;

        if ($stated === null && $type === SafetyCase::TYPE_APPEAL) {
            $stated = $this->answerFor($roles['appeal_target'] ?? null, $answers);
        }

        if ($type !== SafetyCase::TYPE_APPEAL) {
            return new AppealMatch(
                $stated === null ? null : Sanction::query()->where('reference', $stated)->first(),
            );
        }

        return $this->appeals->parse($reporter, $stated, $this->appealText($input, $answers));
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private function answerFor(mixed $field, array $answers): ?string
    {
        if (! is_string($field) || $field === '') {
            return null;
        }

        $value = $answers[$field] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $answers
     */
    private function appealText(array $input, array $answers): string
    {
        $parts = [(string) ($input['summary'] ?? '')];

        array_walk_recursive($answers, function ($value) use (&$parts) {
            if (is_scalar($value)) {
                $parts[] = (string) $value;
            }
        });

        return implode("\n", array_filter($parts));
    }
}
