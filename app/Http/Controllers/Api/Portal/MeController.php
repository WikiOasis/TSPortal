<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            'preferences.themes.*.gradient' => ['nullable', 'array'],
            'preferences.themes.*.gradient.stops' => ['required_with:preferences.themes.*.gradient', 'array', 'min:2', 'max:5'],
            'preferences.themes.*.gradient.stops.*' => ['string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'preferences.themes.*.gradient.angle' => ['nullable', 'integer', 'min:0', 'max:360'],

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
            'bio' => ['nullable', 'string', 'max:2000'],
            'theme' => ['nullable', 'array'],
            'theme.accent' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme.background' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme.gradient' => ['nullable', 'array'],
            'theme.gradient.stops' => ['required_with:theme.gradient', 'array', 'min:2', 'max:5'],
            'theme.gradient.stops.*' => ['string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme.gradient.angle' => ['nullable', 'integer', 'min:0', 'max:360'],
        ]);

        $user = $request->user();
        $user->display_name = isset($data['display_name']) && trim($data['display_name']) !== '' ? trim($data['display_name']) : null;
        $user->bio = isset($data['bio']) && trim($data['bio']) !== '' ? trim($data['bio']) : null;

        if ($request->has('theme')) {
            $theme = array_filter([
                'accent' => $data['theme']['accent'] ?? null,
                'background' => $data['theme']['background'] ?? null,
                'gradient' => $data['theme']['gradient'] ?? null,
            ]);

            $user->profile_theme = $theme === [] ? null : $theme;
        }

        $user->save();

        return response()->json([
            'display_name' => $user->display_name,
            'bio' => $user->bio,
            'shown_name' => $user->shownName(),
            'profile_theme' => $user->profile_theme,
        ]);
    }

    public function storeAvatar(Request $request): JsonResponse
    {
        [$bytes, $ext] = $this->readImage($request, 2048);

        $user = $request->user();
        $disk = Storage::disk($this->disk());

        $path = sprintf('avatars/%d-%s.%s', $user->id, Str::random(8), $ext);
        $this->put($path, $bytes);

        if ($user->avatar_path !== null) {
            $disk->delete($user->avatar_path);
        }

        $user->forceFill(['avatar_path' => $path, 'avatar_version' => Str::random(8)])->save();

        return response()->json(['avatar_url' => $user->avatarUrl()]);
    }

    public function storeBanner(Request $request): JsonResponse
    {
        [$bytes, $ext] = $this->readImage($request, 4096);

        $user = $request->user();

        $path = sprintf('banners/%d-%s.%s', $user->id, Str::random(8), $ext);
        $this->put($path, $bytes);

        if ($user->banner_path !== null) {
            Storage::disk($this->disk())->delete($user->banner_path);
        }

        $user->forceFill(['banner_path' => $path, 'banner_version' => Str::random(8)])->save();

        return response()->json(['banner_url' => $user->bannerUrl()]);
    }

    public function destroyBanner(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->banner_path !== null) {
            Storage::disk($this->disk())->delete($user->banner_path);
            $user->forceFill(['banner_path' => null, 'banner_version' => null])->save();
        }

        return response()->json(['banner_url' => null]);
    }

    public function banner(User $user): StreamedResponse|JsonResponse
    {
        return $this->serve($user->banner_path, 'private, max-age=86400', 'no-banner');
    }

    public function storeBackground(Request $request): JsonResponse
    {
        [$bytes, $ext] = $this->readImage($request, 8192);

        $name = sprintf('%d-%s.%s', $request->user()->id, Str::random(10), $ext);
        $this->put('backgrounds/'.$name, $bytes);

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
        if (preg_match('/^\d+-[A-Za-z0-9]+\.[a-z]+$/', $name) !== 1) {
            return response()->json(['error' => 'no-file'], 404);
        }

        return $this->serve('backgrounds/'.$name, 'private, max-age=604800, immutable', 'no-file');
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
        return $this->serve($user->avatar_path, 'private, max-age=86400', 'no-avatar');
    }

    /**
     * @return array{0: string, 1: string} The bytes and the extension to store them under.
     */
    private function readImage(Request $request, int $maxKb): array
    {
        $request->validate(['file' => ['required', 'file', 'max:'.$maxKb]], [
            'file.uploaded' => 'The picture did not reach the portal. It is probably over the server\'s upload limit; try a smaller one.',
            'file.max' => 'That picture is over '.round($maxKb / 1024).' MB. Try a smaller one.',
        ]);

        $bytes = (string) $request->file('file')->get();
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo === false ? null : finfo_buffer($finfo, $bytes);

        if (! is_string($mime) || ! isset(self::AVATAR_MIME[$mime])) {
            throw ValidationException::withMessages(['file' => 'Use a PNG, JPEG, WebP or GIF picture.']);
        }

        return [$bytes, self::AVATAR_MIME[$mime]];
    }

    private function put(string $path, string $bytes): void
    {
        if (Storage::disk($this->disk())->put($path, $bytes) === true) {
            return;
        }

        Log::error('Profile picture could not be written to storage.', ['disk' => $this->disk(), 'path' => $path]);

        throw ValidationException::withMessages([
            'file' => 'The picture could not be saved to storage. Try again, and tell an admin if it keeps happening.',
        ]);
    }

    private function serve(?string $path, string $cache, string $missing): StreamedResponse|JsonResponse
    {
        $disk = Storage::disk($this->disk());

        if ($path === null || ! $disk->exists($path)) {
            return response()->json(['error' => $missing], 404);
        }

        $types = array_flip(self::AVATAR_MIME);
        $type = $types[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';

        return $disk->response($path, null, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $cache,
        ]);
    }

    private function disk(): string
    {
        $disk = (string) config('attachments.disk', '');

        return $disk !== '' ? $disk : (string) config('filesystems.default', 'local');
    }
}
