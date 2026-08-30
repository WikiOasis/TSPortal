<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Wiki;

use App\Http\Controllers\Controller;
use App\Models\SafetyCase;
use App\Models\Subject;
use App\Services\Safety\AttachmentStore;
use App\Services\Safety\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttachmentController extends Controller
{
    public function __construct(private readonly AttachmentStore $files) {}

    public function store(Request $request, string $reference): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:128'],
            'modified' => ['nullable', 'integer'],

            'content' => ['required', 'string', 'max:'.(int) (config('attachments.max_bytes', 0) * 2 + 4096)],

            'central_id' => ['nullable', 'integer'],
            'username' => ['nullable', 'string', 'max:255'],
        ]);

        $case = SafetyCase::query()->where('reference', $reference)->first();

        if ($case === null || ! $this->belongsToCaller($case, $request)) {
            return response()->json([
                'error' => 'not-found',
                'message' => 'There is no such case on this wiki.',
            ], 404);
        }

        if (! $this->reporterMatches($case, $data)) {
            return response()->json([
                'error' => 'not-found',
                'message' => 'There is no such case for this account.',
            ], 404);
        }

        if (! $case->isOpen()) {
            return response()->json([
                'error' => 'closed',
                'message' => 'This case is closed. Reply to it and Trust & Safety will reopen it.',
            ], 409);
        }

        if ($this->files->countFor($case) >= $this->files->maxPerCase()) {
            return response()->json([
                'error' => 'too-many',
                'message' => sprintf('This case already has %d files.', $this->files->maxPerCase()),
            ], 409);
        }

        $bytes = base64_decode($data['content'], true);

        if ($bytes === false) {
            return response()->json([
                'error' => 'bad-content',
                'message' => 'The file content was not valid base64.',
            ], 422);
        }

        $attachment = $this->files->store(
            $case,
            $data['name'],
            $bytes,
            $data['type'] ?? null,
            $data['modified'] ?? null,
        );

        Audit::log('attachment.received', $case, [
            'attachment_id' => $attachment->id,
            'name' => $attachment->name,
            'size' => $attachment->size,
            'mime' => $attachment->mime,
            'state' => $attachment->state(),
            'refused' => $attachment->refused_reason,
        ], actorLabel: 'wiki');

        return response()->json([
            'id' => $attachment->id,
            'name' => $attachment->name,
            'stored' => $attachment->hasFile(),
            'reason' => $attachment->refused_reason,
        ], 201);
    }

    private function belongsToCaller(SafetyCase $case, Request $request): bool
    {
        $caller = $request->attributes->get('wiki');

        return $case->wiki !== null && $case->wiki !== '' && $case->wiki === $caller;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function reporterMatches(SafetyCase $case, array $data): bool
    {
        if ($case->anonymous || $case->reporter_subject_id === null) {
            return true;
        }

        $case->loadMissing('reporter');
        $reporter = $case->reporter;

        if ($reporter === null) {
            return false;
        }

        $centralId = $data['central_id'] ?? null;
        if ($centralId !== null && $reporter->mw_central_id !== null) {
            return (int) $centralId === (int) $reporter->mw_central_id;
        }

        $username = $data['username'] ?? null;

        return is_string($username)
            && $username !== ''
            && Subject::key($username) === $reporter->username_key;
    }
}
