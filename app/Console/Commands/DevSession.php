<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class DevSession extends Command
{
    protected $signature = 'portal:dev-session {username=M.Okonjo : Portal username to sign in as}';

    protected $description = 'Local only: mint a signed-in session cookie, so Authentik is not needed';

    public function handle(): int
    {
        if (! app()->environment('local', 'sandbox', 'testing')) {
            $this->error('Refusing to run outside a local or sandbox environment.');

            return self::FAILURE;
        }

        $user = User::query()->where('username', $this->argument('username'))->first();

        if ($user === null) {
            $this->error('No such portal user.');

            return self::FAILURE;
        }

        $session = app('session')->driver();
        $session->setId(Str::random(40));
        $session->start();
        $session->put(Auth::guard('web')->getName(), $user->getAuthIdentifier());
        $session->regenerateToken();
        $session->save();

        $name = config('session.cookie');
        $value = app('encrypter')->encrypt(
            CookieValuePrefix::create($name, app('encrypter')->getKey()).$session->getId(),
            false,
        );

        $this->line("document.cookie = '{$name}=".rawurlencode($value)."; path=/';");

        return self::SUCCESS;
    }
}
