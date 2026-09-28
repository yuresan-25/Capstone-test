<?php

namespace App\Console\Commands;

use App\Models\Parents;
use App\Models\StudentEnrollment;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Load testing only: creates logged-in sessions for one admin and N parents
 * in the LOAD TEST database and writes their session cookies to a file, so a
 * load tool (ab / k6) can hit authenticated pages without going through the
 * reCAPTCHA-protected login. Refuses to run on any database whose name
 * doesn't contain "loadtest". See tests/load/README.md.
 */
class LoadTestSessions extends Command
{
    protected $signature = 'loadtest:sessions {--parents=50} {--out=storage/app/loadtest-cookies.json}';

    protected $description = 'Create logged-in test sessions + cookies for load testing (loadtest database only)';

    public function handle(): int
    {
        $database = DB::connection()->getDatabaseName();
        if (! str_contains($database, 'loadtest')) {
            $this->error("Refusing to run on '{$database}' — only on a database whose name contains 'loadtest'.");
            return self::FAILURE;
        }
        if (config('session.driver') !== 'database') {
            $this->error('This command expects SESSION_DRIVER=database.');
            return self::FAILURE;
        }

        $admin = User::where('role', 'admin')->first();
        $parentIds = StudentEnrollment::where('status', 'enrolled')->distinct()
            ->orderBy('user_id')->limit((int) $this->option('parents'))->pluck('user_id');

        $out = [
            'admin'   => $this->cookieFor('web', $admin),
            'parents' => Parents::whereIn('id', $parentIds)->get()->map(fn (Parents $p) => [
                'enrollment_id' => StudentEnrollment::where('user_id', $p->id)->where('status', 'enrolled')->value('id'),
                'cookie'        => $this->cookieFor('parent', $p),
            ])->values(),
        ];

        file_put_contents(base_path($this->option('out')), json_encode($out, JSON_PRETTY_PRINT));
        $this->info('Wrote 1 admin + ' . count($out['parents']) . ' parent session cookies to ' . $this->option('out'));

        return self::SUCCESS;
    }

    /** "cookie-name=value" for a fresh session logged in as $user on $guard. */
    private function cookieFor(string $guard, $user): string
    {
        $g = auth($guard);
        $store = $g->getSession();
        $store->setId(Str::random(40));
        $store->start();
        $store->flush();
        $store->put($g->getName(), $user->getAuthIdentifier());
        $store->getHandler()->setExists(false); // insert a new row per user
        $store->save();

        $name = config('session.cookie');
        $encrypter = app('encrypter');
        $value = $encrypter->encrypt(CookieValuePrefix::create($name, $encrypter->getKey()) . $store->getId(), false);

        return $name . '=' . rawurlencode($value);
    }
}
