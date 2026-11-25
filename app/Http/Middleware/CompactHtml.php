<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 25-11-2026, faster loading: full HTML pages are sent without the Blade indentation. Only the spaces
 * and tabs at the start and end of lines go; line breaks stay, so words are never joined and
 * `white-space: pre-line` text keeps its lines. <pre>, <textarea>, <script> and <style> are left as
 * they are. Downloads, PDFs, JSON and streamed responses are not touched.
 */
class CompactHtml
{
    private const KEEP = '~(<(pre|textarea|script|style)\b[^>]*>.*?</\2\s*>)~is';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse
            || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')
            || $response->headers->has('Content-Disposition')) {
            return $response;
        }
        $html = $response->getContent();
        if (! is_string($html) || $html === '') {
            return $response;
        }

        $original = $response->original ?? null; // keep the view (tests read it with assertViewHas)
        $response->setContent(self::compact($html));
        if ($original !== null && property_exists($response, 'original')) {
            $response->original = $original;
        }
        if ($response->headers->has('Content-Length')) {
            $response->headers->set('Content-Length', (string) strlen($response->getContent()));
        }

        return $response;
    }

    public static function compact(string $html): string
    {
        $parts = preg_split(self::KEEP, $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html; // regex limit on a huge page: send it as it is
        }
        $out = '';
        // preg_split with two groups: text, whole kept element, tag name, text, …
        for ($i = 0, $n = count($parts); $i < $n; $i++) {
            if ($i % 3 === 0) {
                $out .= preg_replace(['~\r\n?~', '~\n[ \t]+~', '~[ \t]+\n~'], ["\n", "\n", "\n"], $parts[$i]) ?? $parts[$i];
            } elseif ($i % 3 === 1) {
                $out .= $parts[$i];
            }
        }

        return $out;
    }
}
