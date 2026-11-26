<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 26-11-2026: download buttons stay busy ("Preparing PDF…", no repeat click) until the file arrives.
 *
 * resources/js/download-busy.js adds `_dl=<token>` to the link. This middleware takes it out of the request (so
 * controllers, filter chips and the queued export's saved URL never see it) and, when the answer is a file, sets
 * the readable cookie `si_dl=<token>` that tells the page the download has started.
 */
class MarkDownloadDone
{
    public const PARAM = '_dl';

    public const COOKIE = 'si_dl';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->query(self::PARAM);
        if ($token === null) {
            return $next($request);
        }

        $request->query->remove(self::PARAM);
        parse_str((string) $request->server->get('QUERY_STRING', ''), $q);
        unset($q[self::PARAM]);
        $request->server->set('QUERY_STRING', http_build_query($q));

        $response = $next($request);

        if (is_string($token) && preg_match('/^[A-Za-z0-9]{8,40}$/', $token) && $this->isFile($response)) {
            $response->headers->setCookie(new Cookie(self::COOKIE, $token, time() + 60, '/', null, $request->isSecure(), false, false, Cookie::SAMESITE_LAX));
        }

        return $response;
    }

    private function isFile(Response $response): bool
    {
        return $response instanceof BinaryFileResponse || $response instanceof StreamedResponse
            || str_contains(strtolower((string) $response->headers->get('Content-Disposition')), 'attachment');
    }
}
