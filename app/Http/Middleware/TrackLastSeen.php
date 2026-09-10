<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tracks lightweight online presence for each authenticated API user.
 *
 * Writes the user id into a cache set ("online-users") with a TTL per
 * entry. The NotificationService uses this to decide whether to email a
 * user about a new chat message (offline users only) and to include
 * online status in emails.
 *
 * The cache write happens on every authenticated API request, so it is
 * throttled to once per minute per user to keep overhead negligible.
 */
class TrackLastSeen
{
    public const ONLINE_KEY = 'online-users';

    public const ONLINE_TTL = 300; // 5 minutes of presence per ping

    public const WRITE_THROTTLE = 60; // only touch cache once a minute per user

    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            $throttleKey = "last-seen-write:{$user->id}";

            if (! Cache::has($throttleKey)) {
                Cache::put($throttleKey, true, self::WRITE_THROTTLE);

                // Mark user as online (renewed on every throttled ping)
                Cache::put(self::ONLINE_KEY.":{$user->id}", true, self::ONLINE_TTL);
            }
        }

        return $next($request);
    }
}
