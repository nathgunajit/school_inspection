<?php

namespace App\Support;

use Illuminate\Contracts\Session\Session;

/**
 * Math CAPTCHA for the login page: a small sum ("7 + 4 = ?") drawn as an image, answer kept in the session only.
 * A question is used once: every check (right or wrong) issues a new one, so an answer cannot be replayed.
 */
class MathCaptcha
{
    private const KEY = 'math_captcha';

    public function __construct(private Session $session) {}

    public static function enabled(): bool
    {
        return (bool) config('inspection.login.math_captcha');
    }

    /** Make a new question and return its text (only used to draw the image). */
    public function refresh(): string
    {
        $op = random_int(0, 2);
        [$a, $b] = match ($op) {
            0 => [random_int(1, 20), random_int(1, 20)],
            1 => [random_int(5, 20), random_int(1, 9)],
            default => [random_int(2, 9), random_int(2, 5)],
        };
        [$sign, $answer] = match ($op) {
            0 => ['+', $a + $b],
            1 => ['−', $a - $b],
            default => ['×', $a * $b],
        };
        $question = "$a $sign $b";
        $this->session->put(self::KEY, ['q' => $question, 'h' => hash_hmac('sha256', (string) $answer, (string) config('app.key'))]);

        return $question;
    }

    /** The current question, creating one if needed. */
    public function question(): string
    {
        return $this->session->get(self::KEY.'.q') ?? $this->refresh();
    }

    /** Checks the answer and always replaces the question. */
    public function check(mixed $answer): bool
    {
        $hash = $this->session->get(self::KEY.'.h');
        $this->refresh();
        $answer = trim((string) $answer);

        return $hash !== null && preg_match('/^-?\d{1,4}$/', $answer)
            && hash_equals($hash, hash_hmac('sha256', (string) (int) $answer, (string) config('app.key')));
    }

    /** The question drawn as an SVG with jittered characters and noise lines. */
    public function svg(): string
    {
        $chars = mb_str_split($this->question().' = ?');
        $w = 34 + count($chars) * 17;
        $out = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="56" viewBox="0 0 '.$w.' 56">'
            .'<rect width="100%" height="100%" rx="10" fill="#f1f6fd"/>';
        for ($i = 0; $i < 5; $i++) {
            $out .= sprintf('<path d="M%d %d Q %d %d %d %d" stroke="%s" stroke-width="1.3" fill="none" opacity=".45"/>',
                random_int(0, 20), random_int(5, 50), random_int(30, $w - 30), random_int(0, 56), $w - random_int(0, 20), random_int(5, 50),
                ['#1565c0', '#1e9b4b', '#f39223'][$i % 3]);
        }
        $x = 18;
        foreach ($chars as $c) {
            if ($c === ' ') { $x += 7; continue; }
            $out .= sprintf('<text x="%d" y="%d" transform="rotate(%d %d 30)" font-family="Verdana,Arial,sans-serif" font-size="%d" font-weight="700" fill="%s">%s</text>',
                $x, random_int(34, 40), random_int(-14, 14), $x, random_int(22, 26), ['#0d47a1', '#1565c0', '#14532d', '#7c2d12'][random_int(0, 3)], e($c));
            $x += 17;
        }

        return $out.'</svg>';
    }
}
