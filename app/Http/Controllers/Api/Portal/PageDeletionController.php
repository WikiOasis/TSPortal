<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\Investigation;
use App\Models\Sanction;
use App\Services\Safety\PageDeletions;
use App\Services\Safety\Pages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PageDeletionController extends Controller
{
    public function __construct(private readonly PageDeletions $deletions) {}

    public function store(Request $request, Investigation $investigation): JsonResponse
    {
        $data = $request->validate([
            'page_ids' => ['required', 'array', 'min:1', 'max:'.Pages::MAX_PER_ACTION],
            'page_ids.*' => ['integer'],

            'reason' => ['required', 'string', 'min:1', 'max:2000'],
            'internal_reason' => ['nullable', 'string', 'max:20000'],
            'reason_category' => [
                'nullable',
                'string',
                'in:'.implode(',', array_keys((array) config('categories.action_reasons', []))),
            ],

            'notify' => ['nullable', 'array', 'max:'.PageDeletions::MAX_NOTICES],
            'notify.*.subject_id' => ['nullable', 'integer'],
            'notify.*.username' => ['nullable', 'string', 'max:255'],
            'notify.*.page_ids' => ['nullable', 'array', 'max:'.Pages::MAX_PER_ACTION],
            'notify.*.page_ids.*' => ['integer'],
            'notice_type' => ['nullable', 'string', 'in:'.implode(',', Sanction::NOTICE_TYPES)],
            'notice_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $outcome = $this->deletions->run($investigation, $request->user(), $data);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'not-actionable', 'message' => $e->getMessage()], 422);
        }

        $deletion = $outcome['deletion'];

        return response()->json([
            'reference' => $deletion->reference,
            'push_state' => $deletion->push_state,
            'push_error' => $deletion->push_error,
            'needs_manual_action' => in_array($deletion->push_state, Sanction::NEEDS_A_PERSON, true),
            'pages' => count($deletion->pages ?? []),
            'wikis' => $deletion->wikis,
            'cases' => $outcome['cases'],
            'notices' => $outcome['notices'],
            'skipped' => $outcome['skipped'],
            'done' => $outcome['done'],
            'failed' => $outcome['failed'],
        ], 201);
    }
}
