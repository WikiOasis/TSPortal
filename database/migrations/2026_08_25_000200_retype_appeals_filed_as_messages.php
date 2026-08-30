<?php

use App\Models\SafetyCase;
use App\Models\Subject;
use App\Services\Safety\AppealMatch;
use App\Services\Safety\AppealParser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $ids = (array) config('categories.appeal_categories', []);
        $group = (string) config('categories.appeal_group', 'appeal');

        if ($ids === [] && $group === '') {
            return;
        }

        $cases = SafetyCase::query()
            ->whereIn('type', [SafetyCase::TYPE_CONTACT, SafetyCase::TYPE_REPORT])
            ->whereHas('categories', function ($q) use ($ids, $group) {
                $q->where(function ($inner) use ($ids, $group) {
                    if ($ids !== []) {
                        $inner->whereIn('category', $ids);
                    }
                    if ($group !== '') {
                        $inner->orWhere('group', $group);
                    }
                });
            })
            ->with('reporter')
            ->get();

        if ($cases->isEmpty()) {
            return;
        }

        $parser = new AppealParser;

        foreach ($cases as $case) {
            DB::transaction(function () use ($case, $parser) {
                $case->type = SafetyCase::TYPE_APPEAL;

                $generic = ['Message to Trust & Safety', 'Appeal', ''];

                if ($case->sanction_id === null) {
                    $match = $this->matchFor($parser, $case);

                    if ($match->linked()) {
                        $case->sanction_id = $match->sanction->id;
                        $case->investigation_id ??= $match->sanction->investigation_id;
                    }

                    $case->appeal_link_source = $match->source;
                    $case->appeal_link_confidence = $match->confidence;
                    $case->appeal_link_notes = $match->toRecord() + [
                        'backfilled' => 'Re-read as an appeal when the portal learned that the '
                            .'contact wizard files them too.',
                    ];

                    if ($match->linked() && in_array($case->subject_line, $generic, true)) {
                        $case->subject_line = sprintf(
                            'Appeal against %s (%s)',
                            $match->sanction->label,
                            $match->sanction->reference,
                        );
                    }
                } else {
                    $case->appeal_link_source ??= AppealMatch::SOURCE_STATED;
                    $case->appeal_link_confidence ??= AppealMatch::LIKELY;
                }

                $case->save();
            });
        }
    }

    private function matchFor(AppealParser $parser, SafetyCase $case): AppealMatch
    {
        $answers = (array) ($case->answers ?? []);

        $stated = null;
        foreach ($answers as $value) {
            if (is_string($value) && preg_match('/^\s*[A-Z]{2,4}-\d{4}-\d{1,6}\s*$/i', $value)) {
                $stated = trim($value);
                break;
            }
        }

        $text = [];
        array_walk_recursive($answers, function ($value) use (&$text) {
            if (is_scalar($value)) {
                $text[] = (string) $value;
            }
        });
        $text[] = (string) $case->summary;

        return $parser->parse(
            $case->reporter instanceof Subject ? $case->reporter : null,
            $stated,
            implode("\n", array_filter($text)),
        );
    }

    public function down(): void {}
};
