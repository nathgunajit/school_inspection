<?php

namespace App\Support;

use App\Models\User;

/**
 * 25-11-2026, one user per browser: every signed-in page carries a token naming its user
 * (<meta name="si-user">). The browser sends it back with each save (`_si_user` field / `X-SI-User`
 * header), so a tab left open by an officer who was replaced by another sign-in is refused
 * (EnsurePageUser) and shows the "another user signed in" message (session-guard.js).
 * The same user signing in again keeps the same token.
 */
final class PageUser
{
    public const FIELD = '_si_user';

    public const HEADER = 'X-SI-User';

    public static function token(User|int $user): string
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return substr(hash_hmac('sha256', 'page-user|'.$id, (string) config('app.key')), 0, 32);
    }
}
