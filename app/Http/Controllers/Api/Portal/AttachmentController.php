<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Services\Safety\AttachmentStore;
use App\Services\Safety\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function __construct(private readonly AttachmentStore $files) {}

    public function show(Request $request, Attachment $attachment): StreamedResponse|JsonResponse
    {
        $stream = $this->files->readStream($attachment);

        if ($stream === null) {
            return response()->json([
                'error' => 'no-file',
                'state' => $attachment->state(),
                'message' => $attachment->refused_reason
                    ?? 'The bytes for this file have not arrived.',
            ], 404);
        }

        $attachment->loadMissing('safetyCase');

        Audit::log('attachment.read', $attachment->safetyCase, [
            'attachment_id' => $attachment->id,
            'name' => $attachment->name,
            'checksum' => $attachment->checksum,
        ]);

        $mime = (string) ($attachment->mime ?: 'application/octet-stream');

        return response()->stream(function () use ($stream) {
            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) ($attachment->size ?? ''),
            'Content-Disposition' => sprintf(
                '%s; filename="%s"',
                $this->inlineSafe($mime) ? 'inline' : 'attachment',
                addcslashes($attachment->name, '"\\'),
            ),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function inlineSafe(string $mime): bool
    {
        return in_array($mime, [
            'image/png',
            'image/jpeg',
            'image/gif',
            'image/webp',
            'application/pdf',
            'text/plain',
            'video/mp4',
            'video/webm',
            'audio/mpeg',
            'audio/ogg',
        ], true);
    }
}
