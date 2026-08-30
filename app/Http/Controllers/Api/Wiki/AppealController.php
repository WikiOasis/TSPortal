<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Wiki;

use App\Http\Controllers\Controller;
use App\Models\SafetyCase;
use App\Models\Subject;
use App\Services\Safety\AppealMatch;
use App\Services\Safety\AppealParser;
use App\Services\Safety\CaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppealController extends Controller
{
    public function __construct(
        private readonly CaseService $cases,
        private readonly AppealParser $parser,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'central_id' => ['nullable', 'integer'],
            'email' => ['nullable', 'email', 'max:255'],
            'sanction_reference' => ['nullable', 'string', 'max:32'],
            'body' => ['required', 'string', 'min:1', 'max:20000'],
            'wiki' => ['nullable', 'string', 'max:64'],
            'authenticated' => ['boolean'],
        ]);

        $subject = Subject::forUsername($data['username'], $data['central_id'] ?? null);

        if (! empty($data['email']) && ($subject->email === null || $subject->email === '')) {
            $subject->forceFill(['email' => $data['email']])->save();
        }

        $match = $this->parser->parse($subject, $data['sanction_reference'] ?? null, $data['body']);

        $duplicate = $this->openAppealAgainst($match);

        if ($duplicate !== null) {
            return response()->json([
                'reference' => $duplicate->reference,
                'duplicate' => true,
                'message' => 'An appeal against this action is already open with Trust & Safety.',
            ], 200);
        }

        $case = $this->cases->createFromSubmission([
            'type' => SafetyCase::TYPE_APPEAL,
            'flow' => 'appeal',
            'wiki' => $data['wiki'] ?? $request->attributes->get('wiki'),
            'anonymous' => false,
            'reporter' => [
                'central_id' => $data['central_id'] ?? null,
                'username' => $data['username'],
                'email' => $data['email'] ?? null,
            ],
            'subject' => $match->linked()
                ? sprintf('Appeal against %s (%s)', $match->sanction->label, $match->sanction->reference)
                : 'Appeal',
            'summary' => $data['body'],
            'sanction_reference' => $data['sanction_reference'] ?? null,
            'answers' => [
                'body' => $data['body'],
                'authenticated' => (bool) ($data['authenticated'] ?? false),
            ],
        ]);

        return response()->json([
            'reference' => $case->reference,
            'duplicate' => false,
            'status' => $case->status,
        ], 201);
    }

    private function openAppealAgainst(AppealMatch $match): ?SafetyCase
    {
        if (! $match->linked() || $match->confidence === AppealMatch::GUESS) {
            return null;
        }

        return SafetyCase::query()
            ->where('type', SafetyCase::TYPE_APPEAL)
            ->where('sanction_id', $match->sanction->id)
            ->whereIn('status', SafetyCase::OPEN_STATUSES)
            ->first();
    }
}
