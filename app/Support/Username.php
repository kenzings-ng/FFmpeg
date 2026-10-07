<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use App\Models\UsernameChange;
use Illuminate\Support\Str;

/**
 * Handle (@username) duy nhất của người dùng, kiểu YouTube.
 *
 * - 3–30 ký tự a-z 0-9 . _ ; bắt đầu và kết thúc bằng chữ / số; không có "..".
 * - Lưu chữ thường; so sánh không phân biệt hoa thường (luôn normalize trước).
 * - Đổi tối đa CHANGE_LIMIT lần / CHANGE_WINDOW_DAYS ngày; handle vừa bỏ được
 *   giữ CHANGE_WINDOW_DAYS ngày (người khác không lấy được) để tránh mạo danh.
 *
 * Quy tắc định dạng PHẢI khớp FE: utils/username.ts.
 */
final class Username
{
    public const MIN = 3;

    public const MAX = 30;

    public const PATTERN = '/^[a-z0-9](?:[a-z0-9._]*[a-z0-9])?$/';

    /** "@handle" trong văn bản; không khớp email (ký tự liền trước '@' không được là chữ / số / . _). */
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
     * Lý do handle không dùng được (tiếng Việt), hoặc null nếu dùng được.
     * $owner: người đang muốn dùng handle này (bỏ qua chính handle hiện tại của họ).
     */
    public static function problem(string $value, ?User $owner = null): ?string
    {
        $username = self::normalize($value);
        $length = strlen($username);

        if ($length < self::MIN || $length > self::MAX) {
            return 'Tên người dùng phải dài '.self::MIN.'–'.self::MAX.' ký tự.';
        }

        if (! preg_match(self::PATTERN, $username) || str_contains($username, '..')) {
            return 'Chỉ dùng chữ thường không dấu, số, dấu chấm và gạch dưới; bắt đầu và kết thúc bằng chữ hoặc số.';
        }

        if (in_array($username, self::RESERVED, true)) {
            return 'Tên người dùng này đã được hệ thống giữ lại.';
        }

        if ($owner && $owner->username === $username) {
            return null;
        }

        $taken = User::where('username', $username)->when($owner, fn ($q) => $q->whereKeyNot($owner->id))->exists()
            || UsernameChange::where('username', $username)
                ->where('created_at', '>', now()->subDays(self::CHANGE_WINDOW_DAYS))
                ->when($owner, fn ($q) => $q->where('user_id', '!=', $owner->id))
                ->exists();

        return $taken ? 'Tên người dùng này đã có người dùng.' : null;
    }

    /** Sinh handle chưa ai dùng từ tên hiển thị, vd. "Bảo Nguyễn" → "baonguyen" / "baonguyen2". */
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

    /** Chuỗi để tìm không dấu: "Đặng Bình" → "dang binh". */
    public static function searchable(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii($text))) ?? '');
    }

    /**
     * Các handle được nhắc trong văn bản (đã normalize, không trùng).
     *
     * @return array<int, string>
     */
    public static function mentionedIn(string $text, int $limit = 20): array
    {
        preg_match_all(self::MENTION_PATTERN, $text, $matches);

        return array_slice(array_values(array_unique(array_map(self::normalize(...), $matches[1]))), 0, $limit);
    }
}
