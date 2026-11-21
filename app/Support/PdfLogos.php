<?php

namespace App\Support;

/** Official header logos (Modification V4) as data URIs, so DomPDF needs no file or remote access. */
class PdfLogos
{
    /** @return array{assam: ?string, ssa: ?string} */
    public static function all(): array
    {
        $logo = function (string $file): ?string {
            $path = public_path('logo/'.$file);

            return is_file($path) ? 'data:image/png;base64,'.base64_encode(file_get_contents($path)) : null;
        };

        return ['assam' => $logo('ASSAM GOVT LOGO.png'), 'ssa' => $logo('SSA LOGO.png')];
    }
}
