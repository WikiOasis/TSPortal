<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Wiki;

use App\Http\Controllers\Controller;
use App\Models\CaseComment;
use App\Models\SafetyCase;
use App\Models\Subject;
use App\Services\Safety\CaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function __construct(private readonly CaseService $cases) {}

    public function store(Request $request, string $reference): JsonResponse
    {
        $data = $request->validate([
            'central_id' => ['nullable', 'integer'],
            'username' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'min:1', 'max:20000'],
        ]);

        $subject = $data['central_id'] !== null
            ? Subject::query()->where('mw_central_id', $data['central_id'])->first()
            : null;
        $subject ??= Subject::query()->where('username_key', Subject::key($data['username']))->first();

        $case = $subject === null ? null : SafetyCase::query()
            ->visibleTo($subject)
            ->where('reference', $reference)
            ->first();

        if ($case === null) {
            return response()->json([
                'error' => 'not-found',
                'message' => 'There is no such case for this account.',
            ], 404);
        }

        $comment = $this->cases->comment(
            $case,
            $data['body'],
            CaseComment::VISIBILITY_PUBLIC,
            subject: $subject,
        );

        return response()->json([
            'id' => $comment->id,
            'date' => $comment->created_at?->toIso8601String(),
            'status' => $case->fresh()?->status,
        ], 201);
    }
}
