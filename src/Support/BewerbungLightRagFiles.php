<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Support;

class BewerbungLightRagFiles
{
    /** @var list<string> */
    public const DIRECT_EXTENSIONS = [
        'pdf', 'docx', 'txt', 'md', 'pptx', 'xlsx', 'csv', 'html', 'htm', 'rtf', 'odt', 'json', 'xml',
    ];

    /** @var list<string> */
    public const OCR_EXTENSIONS = [
        'pdf', 'jpg', 'jpeg', 'png', 'tif', 'tiff', 'webp', 'bmp', 'heic', 'doc',
    ];

    public static function isDirect(string $filename): bool
    {
        return in_array(self::extension($filename), self::DIRECT_EXTENSIONS, true);
    }

    public static function needsOcr(string $filename): bool
    {
        return in_array(self::extension($filename), self::OCR_EXTENSIONS, true);
    }

    public static function uploadName(int $bewerbungId, string $ordner, string $filename): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $extension = self::extension($filename);
        $safeBase = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $base) ?: 'datei';
        $safeBase = trim((string) $safeBase, '._-') ?: 'datei';

        return 'bewerbung-'.$bewerbungId.'__'.$ordner.'__'.$safeBase.($extension !== '' ? '.'.$extension : '');
    }

    public static function markdownName(int $bewerbungId, string $ordner, string $filename): string
    {
        $base = pathinfo(self::uploadName($bewerbungId, $ordner, $filename), PATHINFO_FILENAME);

        return $base.'.md';
    }

    public static function extension(string $filename): string
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }
}
