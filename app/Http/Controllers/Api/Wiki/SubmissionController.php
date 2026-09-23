<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Wiki;

use App\Http\Controllers\Controller;
use App\Models\SafetyCase;
use App\Services\Safety\CaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubmissionController extends Controller
{
    public function __construct(private readonly CaseService $cases) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', SafetyCase::TYPES)],
            'flow' => ['nullable', 'string', 'max:32'],
            'wiki' => ['nullable', 'string', 'max:64'],
            'anonymous' => ['boolean'],
            'automated' => ['boolean'],
            'reporter' => ['nullable', 'array'],
            'reporter.central_id' => ['nullable', 'integer'],
            'reporter.username' => ['nullable', 'string', 'max:255'],
            'reporter.email' => ['nullable', 'email', 'max:255'],
            'answers' => ['nullable', 'array'],
            'roles' => ['nullable', 'array'],

            'categories' => ['nullable', 'array', 'max:20'],
            'categories.*.id' => ['required_with:categories', 'string', 'max:64'],
            'categories.*.label' => ['nullable', 'string', 'max:250'],
            'categories.*.group' => ['nullable', 'string', 'max:64'],
            'categories.*.field' => ['nullable', 'string', 'max:64'],
            'subject' => ['nullable', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'sanction_reference' => ['nullable', 'string', 'max:32'],
            'attachments' => ['nullable', 'array', 'max:20'],
        ]);

        $data['wiki'] ??= $request->attributes->get('wiki');

        $case = $this->cases->createFromSubmission($data);

        return response()->json([
            'reference' => $case->reference,
            'status' => $case->status,
            'filed' => $case->created_at?->toIso8601String(),
            'listed' => ! $case->anonymous,
        ], 201);
    }
}
