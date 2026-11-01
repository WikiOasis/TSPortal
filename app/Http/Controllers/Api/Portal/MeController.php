<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MeController extends Controller
{
    private const AVATAR_MIME = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.theme' => ['nullable', 'string', 'in:system,light,dark'],
            'preferences.density' => ['nullable', 'string', 'in:roomy,compact'],
            'preferences.textSize' => ['nullable', 'string', 'in:small,default,large,xlarge'],
            'preferences.palette' => ['nullable', 'string', 'max:64'],
            'preferences.tint' => ['nullable', 'integer', 'min:0', 'max:16'],
            'preferences.css' => ['nullable', 'string', 'max:20000'],

            'preferences.themes' => ['nullable', 'array', 'max:20'],
            'preferences.themes.*.id' => ['required', 'string', 'max:64'],
            'preferences.themes.*.name' => ['required', 'string', 'max:40'],
            'preferences.themes.*.accent' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'preferences.themes.*.tint' => ['nullable', 'integer', 'min:0', 'max:16'],
            'preferences.themes.*.background' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'preferences.themes.*.image' => ['nullable', 'string', 'regex:#^/api/portal/backgrounds/[A-Za-z0-9._-]+$#'],
            'preferences.themes.*.dim' => ['nullable', 'integer', 'min:0', 'max:90'],

            'preferences.notify.events' => ['nullable', 'array'],
            'preferences.notify.events.*' => ['boolean'],
            'preferences.notify.auto' => ['nullable', 'array'],
            'preferences.notify.auto.*' => ['boolean'],
        ]);

        $user = $request->user();
        $user->preferences = $data['preferences'];
        $user->save();

        return response()->json(['ok' => true]);
    }

    public function profile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['nullable', 'string', 'max:80'],
            'bio' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        $user->display_name = isset($data['display_name']) && trim($data['display_name']) !== '' ? trim($data['display_name']) : null;
        $user->bio = isset($data['bio']) && trim($data['bio']) !== '' ? trim($data['bio']) : null;
        $user->save();

        return response()->json([
            'display_name' => $user->display_name,
            'bio' => $user->bio,
            'shown_name' => $user->shownName(),
        ]);
    }

    public function storeAvatar(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:2048']]);

        $bytes = (string) $request->file('file')->get();
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo === false ? null : finfo_buffer($finfo, $bytes);

        if (! is_string($mime) || ! isset(self::AVATAR_MIME[$mime])) {
            throw ValidationException::withMessages(['file' => 'Use a PNG, JPEG, WebP or GIF picture.']);
        }

        $user = $request->user();
        $disk = Storage::disk($this->disk());

        if ($user->avatar_path !== null) {
            $disk->delete($user->avatar_path);
        }

        $path = sprintf('avatars/%d-%s.%s', $user->id, Str::random(8), self::AVATAR_MIME[$mime]);
        $disk->put($path, $bytes);

        $user->forceFill(['avatar_path' => $path, 'avatar_version' => Str::random(8)])->save();

        return response()->json(['avatar_url' => $user->avatarUrl()]);
    }

    public function storeBackground(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:6144']]);

        $bytes = (string) $request->file('file')->get();
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo === false ? null : finfo_buffer($finfo, $bytes);

        if (! is_string($mime) || ! isset(self::AVATAR_MIME[$mime])) {
            throw ValidationException::withMessages(['file' => 'Use a PNG, JPEG, WebP or GIF picture.']);
        }

        $name = sprintf('%d-%s.%s', $request->user()->id, Str::random(10), self::AVATAR_MIME[$mime]);
        Storage::disk($this->disk())->put('backgrounds/'.$name, $bytes);

        return response()->json(['url' => '/api/portal/backgrounds/'.$name]);
    }

    public function destroyBackground(Request $request, string $name): JsonResponse
    {
        if (preg_match('/^'.$request->user()->id.'-[A-Za-z0-9]+\.[a-z]+$/', $name) === 1) {
            Storage::disk($this->disk())->delete('backgrounds/'.$name);
        }

        return response()->json(['ok' => true]);
    }

    public function background(string $name): StreamedResponse|JsonResponse
    {
        $disk = Storage::disk($this->disk());

        if (preg_match('/^\d+-[A-Za-z0-9]+\.[a-z]+$/', $name) !== 1 || ! $disk->exists('backgrounds/'.$name)) {
            return response()->json(['error' => 'no-file'], 404);
        }

        return $disk->response('backgrounds/'.$name, null, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=604800, immutable',
        ]);
    }

    public function destroyAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->avatar_path !== null) {
            Storage::disk($this->disk())->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null, 'avatar_version' => null])->save();
        }

        return response()->json(['avatar_url' => null]);
    }

    public function avatar(User $user): StreamedResponse|JsonResponse
    {
        $disk = Storage::disk($this->disk());

        if ($user->avatar_path === null || ! $disk->exists($user->avatar_path)) {
            return response()->json(['error' => 'no-avatar'], 404);
        }

        return $disk->response($user->avatar_path, null, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function disk(): string
    {
        $disk = (string) config('attachments.disk', '');

        return $disk !== '' ? $disk : (string) config('filesystems.default', 'local');
    }
}
