<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\InlineImage;
use App\Models\Investigation;
use App\Models\SafetyCase;
use App\Services\Safety\InlineImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InlineImageController extends Controller
{
    public function __construct(private readonly InlineImages $images) {}

    public function forInvestigation(Request $request, Investigation $investigation): JsonResponse
    {
        return $this->upload($request, $investigation);
    }

    public function forCase(Request $request, SafetyCase $case): JsonResponse
    {
        return $this->upload($request, $case);
    }

    public function show(InlineImage $inlineImage): StreamedResponse|JsonResponse
    {
        $stream = $this->images->readStream($inlineImage);

        if ($stream === null) {
            return response()->json([
                'error' => 'no-file',
                'message' => 'The picture is no longer in storage.',
            ], 404);
        }

        return response()->stream(function () use ($stream) {
            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $inlineImage->mime,
            'Content-Length' => (string) $inlineImage->size,
            'Content-Disposition' => sprintf('inline; filename="%s"', addcslashes($inlineImage->name, '"\\')),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400, immutable',
        ]);
    }

    private function upload(Request $request, Investigation|SafetyCase $owner): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file'],
        ]);

        $image = $this->images->store($owner, $request->file('file'), $request->user());

        return response()->json([
            'uuid' => $image->uuid,
            'name' => $image->name,
            'mime' => $image->mime,
            'size' => $image->size,
            'token' => $image->token(),
            'url' => route('portal.inline-images.show', $image, absolute: false),
        ], 201);
    }
}
