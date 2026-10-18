<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use App\Models\UsernameChange;
use Illuminate\Support\Str;

/**
 * Unique user handle (@username), YouTube-style.
 *
 * - 3–30 characters a-z 0-9 . _ ; begins and ends with alphanumeric character; no consecutive dots "..".
 * - Stored in lowercase; case-insensitive comparison (always normalize first).
 * - Max CHANGE_LIMIT changes per CHANGE_WINDOW_DAYS rolling window; previous handle
 *   is held for CHANGE_WINDOW_DAYS (reserved against impersonation).
 *
 * Validation rules MUST match FE: utils/username.ts.
 */
final class Username
{
    public const MIN = 3;

    public const MAX = 30;

    public const PATTERN = '/^[a-z0-9](?:[a-z0-9._]*[a-z0-9])?$/';

    /** "@handle" in text; does not match email (character preceding '@' cannot be alphanumeric/._@). */
    public const MENTION_PATTERN = '/(?<![A-Za-z0-9._@])@([A-Za-z0-9](?:[A-Za-z0-9._]*[A-Za-z0-9])?)/';

    public const CHANGE_LIMIT = 2;

    public const CHANGE_WINDOW_DAYS = 14;

    public const RESERVED = [
        'admin', 'administrator', 'api', 'app', 'help', 'login', 'logout', 'me', 'mod', 'moderator',
        'null', 'official', 'register', 'root', 'support', 'system', 'undefined', 'upload', 'user',
        'users', 'video', 'videos', 'ffmpeg', 'staff', 'security', 'settings', 'profile',
    ];

    public static function normalize(string $value): string
    {
        return Str::lower(ltrim(trim($value), '@'));
    }

    /**
     * Reason why handle cannot be used, or null if available.
     * $owner: current user claiming the handle (ignores their own current handle).
     */
    public static function problem(string $value, ?User $owner = null): ?string
    {
        $username = self::normalize($value);
        $length = strlen($username);

        if ($length < self::MIN || $length > self::MAX) {
            return 'Username must be '.self::MIN.'–'.self::MAX.' characters long.';
        }

        if (! preg_match(self::PATTERN, $username) || str_contains($username, '..')) {
            return 'Only lowercase letters, numbers, dots, and underscores are allowed; must start and end with a letter or number.';
        }

        if (in_array($username, self::RESERVED, true)) {
            return 'This username is reserved by the system.';
        }

        if ($owner && $owner->username === $username) {
            return null;
        }

        $taken = User::where('username', $username)->when($owner, fn ($q) => $q->whereKeyNot($owner->id))->exists()
            || UsernameChange::where('username', $username)
                ->where('created_at', '>', now()->subDays(self::CHANGE_WINDOW_DAYS))
                ->when($owner, fn ($q) => $q->where('user_id', '!=', $owner->id))
                ->exists();

        return $taken ? 'This username is already taken.' : null;
    }

    /** Derives an available handle from display name, e.g. "Bao Nguyen" -> "baonguyen" / "baonguyen2". */
    public static function generate(string $name): string
    {
        $base = substr(preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($name))) ?? '', 0, 20);

        if (strlen($base) < self::MIN || in_array($base, self::RESERVED, true)) {
            $base = 'user'.($base !== '' ? $base : '');
        }

        if (self::problem($base) === null) {
            return $base;
        }

        for ($n = 2; $n < 100; $n++) {
            if (self::problem($base.$n) === null) {
                return $base.$n;
            }
        }

        do {
            $candidate = $base.random_int(100000, 999999);
        } while (self::problem($candidate) !== null);

        return $candidate;
    }

    /** Generates unique random handle, e.g. "user_7k9d2a1b". */
    public static function generateRandom(): string
    {
        do {
            $random = Str::lower(Str::random(8));
            $random = preg_replace('/[^a-z0-9]/', '', $random) ?? '';
            if ($random === '') {
                $random = (string) random_int(10000000, 99999999);
            }
            $candidate = 'user_'.$random;
        } while (self::problem($candidate) !== null);

        return $candidate;
    }

    /** Accent-stripped search string: "Dang Binh" -> "dang binh". */
    public static function searchable(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii($text))) ?? '');
    }

    /**
     * Normalized, unique handles mentioned in text.
     *
     * @return array<int, string>
     */
    public static function mentionedIn(string $text, int $limit = 20): array
    {
        preg_match_all(self::MENTION_PATTERN, $text, $matches);

        return array_slice(array_values(array_unique(array_map(self::normalize(...), $matches[1]))), 0, $limit);
    }
}
