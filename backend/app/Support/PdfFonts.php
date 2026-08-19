<?php

namespace App\Support;

/** Embed TTF files for DomPDF (remote CDNs are Latin-only and skip Khmer glyphs). */
final class PdfFonts
{
    public static function khmerFamily(): string
    {
        return 'Battambang';
    }

    public static function regularDataUri(): ?string
    {
        return self::dataUri('Battambang-Regular.ttf');
    }

    public static function boldDataUri(): ?string
    {
        return self::dataUri('Battambang-Bold.ttf');
    }

    public static function dataUri(string $filename): ?string
    {
        $path = resource_path('fonts/'.$filename);

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        return 'data:font/truetype;base64,'.base64_encode($contents);
    }
}
