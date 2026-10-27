<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Models\InlineImage;
use App\Models\Investigation;
use App\Models\SafetyCase;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InlineImages
{
    public const TOKEN = '/!\[[^\]\n]*\]\(image:([0-9a-f-]{36})\)/i';

    public const MIME = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    public function store(Investigation|SafetyCase $owner, UploadedFile $file, User $actor): InlineImage
    {
        $bytes = (string) $file->get();
        $mime = $this->detect($bytes);

        if ($mime === null || ! isset(self::MIME[$mime])) {
            throw ValidationException::withMessages([
                'file' => 'Only PNG, JPEG, GIF and WebP pictures can be put inline.',
            ]);
        }

        $max = $this->maxBytes();
        if ($max > 0 && strlen($bytes) > $max) {
            throw ValidationException::withMessages([
                'file' => sprintf('The picture is larger than the %d MB limit.', (int) ceil($max / 1048576)),
            ]);
        }

        $uuid = (string) Str::uuid();
        $disk = $this->diskName();
        $path = sprintf('%s/%s/%s.%s', $this->prefix(), $this->folderFor($owner), $uuid, self::MIME[$mime]);

        if (Storage::disk($disk)->put($path, $bytes) === false) {
            throw ValidationException::withMessages([
                'file' => 'The portal could not write the picture to storage.',
            ]);
        }

        $image = InlineImage::create([
            'uuid' => $uuid,
            'investigation_id' => $owner instanceof Investigation ? $owner->id : null,
            'case_id' => $owner instanceof SafetyCase ? $owner->id : null,
            'uploaded_by' => $actor->id,
            'name' => $this->cleanName($file->getClientOriginalName()) ?? 'image.'.self::MIME[$mime],
            'mime' => $mime,
            'size' => strlen($bytes),
            'disk' => $disk,
            'path' => $path,
            'checksum' => hash('sha256', $bytes),
        ]);

        return $image;
    }

    /**
     * @return resource|null
     */
    public function readStream(InlineImage $image)
    {
        $disk = Storage::disk($image->disk);

        return $disk->exists($image->path) ? $disk->readStream($image->path) : null;
    }

    public static function mentions(?string $text): bool
    {
        return $text !== null && preg_match(self::TOKEN, $text) === 1;
    }

    public static function refuseIn(?string $text, string $field): void
    {
        if (self::mentions($text)) {
            throw ValidationException::withMessages([
                $field => 'Pictures are for internal use only. Remove them before sending this outside the portal.',
            ]);
        }
    }

    public static function strip(string $text, string $where): string
    {
        if (! self::mentions($text)) {
            return $text;
        }

        Log::warning('A picture was taken out of text leaving the portal; the form that sent it should refuse pictures.', [
            'where' => $where,
        ]);

        return trim((string) preg_replace(self::TOKEN, '', $text));
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function stripAll(array $payload, string $where): array
    {
        return array_map(fn ($value) => match (true) {
            is_string($value) => self::strip($value, $where),
            is_array($value) => self::stripAll($value, $where),
            default => $value,
        }, $payload);
    }

    public function maxBytes(): int
    {
        return max(0, (int) config('attachments.inline_max_bytes', 10 * 1024 * 1024));
    }

    private function folderFor(Investigation|SafetyCase $owner): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '', (string) $owner->reference) ?: (string) $owner->id;
    }

    private function prefix(): string
    {
        return trim((string) config('attachments.inline_prefix', 'inline-images'), '/');
    }

    private function diskName(): string
    {
        $disk = (string) config('attachments.disk', '');

        return $disk !== '' ? $disk : (string) config('filesystems.default', 'local');
    }

    private function detect(string $bytes): ?string
    {
        if ($bytes === '' || ! function_exists('finfo_open')) {
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

    private function cleanName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $name = basename(str_replace('\\', '/', $name));
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? $name);

        return $name === '' || $name === '.' || $name === '..' ? null : Str::limit($name, 255, '');
    }
}
