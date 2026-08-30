<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Wiki;

use App\Http\Controllers\Controller;
use App\Services\Safety\CheckUserIngest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckUserController extends Controller
{
    public function __construct(private readonly CheckUserIngest $checks) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'wiki' => ['nullable', 'string', 'max:64'],

            'targets' => ['nullable', 'string', 'max:16'],

            'checks' => ['required', 'array', 'max:1000'],
        ]);

        $result = $this->checks->store($data, $request->attributes->get('wiki'));

        return response()->json([
            'ok' => true,
            'stored' => $result['stored'],
            'updated' => $result['updated'],

            'skipped' => $result['skipped'],

            'through' => $result['through'],
        ], 202);
    }
}
