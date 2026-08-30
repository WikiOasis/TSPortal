<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Models\Attachment;
use App\Models\SafetyCase;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class AttachmentStore
{
    /**
     * @param  array<string, mixed>  $file
     */
    public function record(SafetyCase $case, array $file): ?Attachment
    {
        $name = $this->cleanName($file['name'] ?? null);

        if ($name === null) {
            return null;
        }

        if ($this->countFor($case) >= $this->maxPerCase()) {
            return null;
        }

        return Attachment::create([
            'case_id' => $case->id,
            'name' => $name,
            'mime' => $this->cleanMime($file['type'] ?? null),
            'size' => isset($file['size']) ? max(0, (int) $file['size']) : null,
            'file_modified_at' => isset($file['modified']) && is_numeric($file['modified'])
                ? date('Y-m-d H:i:s', intdiv((int) $file['modified'], 1000))
                : null,
        ]);
    }

    /**
     * @param  string  $bytes
     */
    public function store(
        SafetyCase $case,
        string $name,
        string $bytes,
        ?string $declaredMime = null,
        ?int $modifiedAtMs = null,
    ): Attachment {
        $name = $this->cleanName($name) ?? 'attachment';
        $declaredMime = $this->cleanMime($declaredMime);
        $checksum = hash('sha256', $bytes);

        $attachment = $this->pendingRow($case, $name, strlen($bytes))
            ?? new Attachment(['case_id' => $case->id, 'name' => $name]);

        $attachment->case_id = $case->id;
        $attachment->name = $name;
        $attachment->size = strlen($bytes);

        if ($modifiedAtMs !== null && $attachment->file_modified_at === null) {
            $attachment->file_modified_at = date('Y-m-d H:i:s', intdiv($modifiedAtMs, 1000));
        }

        $existing = $this->duplicate($case, $checksum, $attachment->id);
        if ($existing !== null) {
            return $existing;
        }

        $refusal = $this->refusalFor($bytes, $declaredMime);

        if ($refusal !== null) {
            $attachment->mime = $refusal['mime'] ?? $attachment->mime;
            $attachment->path = null;
            $attachment->disk = null;
            $attachment->checksum = $checksum;
            $attachment->refused_reason = $refusal['reason'];
            $attachment->save();

            return $attachment;
        }

        $mime = $this->detect($bytes) ?? (string) $declaredMime;
        $disk = $this->diskName();
        $path = $this->pathFor($case, $mime);

        if (Storage::disk($disk)->put($path, $bytes) === false) {
            $attachment->mime = $mime;
            $attachment->path = null;
            $attachment->disk = null;
            $attachment->checksum = $checksum;
            $attachment->refused_reason = 'The portal could not write the file to storage.';
            $attachment->save();

            return $attachment;
        }

        $attachment->mime = $mime;
        $attachment->path = $path;
        $attachment->disk = $disk;
        $attachment->checksum = $checksum;
        $attachment->uploaded_at = now();
        $attachment->refused_reason = null;
        $attachment->save();

        return $attachment;
    }

    /**
     * @return resource|null
     */
    public function readStream(Attachment $attachment)
    {
        if (! $attachment->hasFile()) {
            return null;
        }

        $disk = $this->diskFor($attachment);

        return $disk->exists($attachment->path) ? $disk->readStream($attachment->path) : null;
    }

    public function contents(Attachment $attachment): ?string
    {
        if (! $attachment->hasFile()) {
            return null;
        }

        $disk = $this->diskFor($attachment);

        return $disk->exists($attachment->path) ? $disk->get($attachment->path) : null;
    }

    public function forget(Attachment $attachment, string $reason = 'Removed.'): void
    {
        if ($attachment->hasFile()) {
            $this->diskFor($attachment)->delete($attachment->path);
        }

        $attachment->forceFill([
            'path' => null,
            'disk' => null,
            'uploaded_at' => null,
            'refused_reason' => Str::limit($reason, 255, ''),
        ])->save();
    }

    public function accepting(): bool
    {
        return (bool) config('attachments.enabled', true);
    }

    public function maxBytes(): int
    {
        return max(0, (int) config('attachments.max_bytes', 0));
    }

    public function maxPerCase(): int
    {
        return max(0, (int) config('attachments.max_per_case', 20));
    }

    public function effectiveMaxBytes(): int
    {
        $limits = array_filter([
            $this->maxBytes(),
            (int) floor(self::iniBytes((string) ini_get('post_max_size')) / 1.4),
        ], fn (int $limit) => $limit > 0);

        return $limits === [] ? 0 : (int) min($limits);
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        return (int) $value * match (strtolower(substr($value, -1))) {
            'g' => 1024 * 1024 * 1024,
            'm' => 1024 * 1024,
            'k' => 1024,
            default => 1,
        };
    }

    /** @return list<string> */
    public function allowedMime(): array
    {
        return array_values(array_filter((array) config('attachments.allowed_mime', [])));
    }

    public function countFor(SafetyCase $case): int
    {
        return $case->attachments()->count();
    }

    /**
     * @return array{reason: string, mime?: ?string}|null
     */
    private function refusalFor(string $bytes, ?string $declaredMime): ?array
    {
        if (! $this->accepting()) {
            return ['reason' => 'This portal is not accepting file uploads.'];
        }

        if ($bytes === '') {
            return ['reason' => 'The file was empty.'];
        }

        $max = $this->maxBytes();
        if ($max > 0 && strlen($bytes) > $max) {
            return ['reason' => sprintf(
                'The file is %s and the limit is %s.',
                $this->readableSize(strlen($bytes)),
                $this->readableSize($max),
            )];
        }

        $detected = $this->detect($bytes);
        $allowed = $this->allowedMime();

        if ($allowed !== [] && ($detected === null || ! in_array($detected, $allowed, true))) {
            return [
                'mime' => $detected,
                'reason' => sprintf(
                    'The portal does not store %s files.',
                    $detected ?? $declaredMime ?? 'files of that type',
                ),
            ];
        }

        return null;
    }

    private function detect(string $bytes): ?string
    {
        if (! function_exists('finfo_open')) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        $mime = finfo_buffer($finfo, $bytes);
        finfo_close($finfo);

        return is_string($mime) && $mime !== '' ? strtolower($mime) : null;
    }

    private function pathFor(SafetyCase $case, string $mime): string
    {
        $prefix = trim((string) config('attachments.prefix', 'case-attachments'), '/');
        $reference = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $case->reference) ?: (string) $case->id;
        $extension = (array) config('attachments.extensions', []);
        $suffix = isset($extension[$mime]) ? '.'.$extension[$mime] : '';

        return sprintf('%s/%s/%s%s', $prefix, $reference, bin2hex(random_bytes(16)), $suffix);
    }

    private function diskName(): string
    {
        $disk = (string) config('attachments.disk', '');

        return $disk !== '' ? $disk : (string) config('filesystems.default', 'local');
    }

    private function diskFor(Attachment $attachment): Filesystem
    {
        return Storage::disk($attachment->disk ?: $this->diskName());
    }

    private function pendingRow(SafetyCase $case, string $name, int $size): ?Attachment
    {
        return $case->attachments()
            ->whereNull('path')
            ->whereNull('refused_reason')
            ->where('name', $name)
            ->where(fn ($q) => $q->whereNull('size')->orWhere('size', $size))
            ->orderBy('id')
            ->first();
    }

    private function duplicate(SafetyCase $case, string $checksum, ?int $exceptId): ?Attachment
    {
        return $case->attachments()
            ->where('checksum', $checksum)
            ->whereNotNull('path')
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->first();
    }

    private function cleanMime(mixed $mime): ?string
    {
        if (! is_string($mime)) {
            return null;
        }

        $mime = strtolower(trim(Str::before($mime, ';')));

        return $mime !== '' && strlen($mime) <= 128 && preg_match('#^[\w.+-]+/[\w.+-]+$#', $mime) === 1
            ? $mime
            : null;
    }

    private function cleanName(mixed $name): ?string
    {
        if (! is_string($name)) {
            return null;
        }

        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? $name;
        $name = trim($name);

        return $name === '' || $name === '.' || $name === '..' ? null : Str::limit($name, 255, '');
    }

    private function readableSize(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1).' MB'
            : max(1, (int) round($bytes / 1024)).' KB';
    }
}
