<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Support;

class BewerbungLightRagPayload
{
    /**
     * @param  array<string, mixed>  $stelle
     * @param  array<string, mixed>  $bewerbung
     * @param  list<string>  $hochgeladen
     * @param  list<string>  $ausgelassen
     */
    public static function text(array $stelle, array $bewerbung, array $hochgeladen, array $ausgelassen): string
    {
        $lines = [
            'Stelle: '.(string) ($stelle['bezeichnung'] ?? '').' (ID '.(string) ($stelle['id'] ?? '').')',
            'Bewerbung: '.(string) ($bewerbung['id'] ?? ''),
            'Name: '.trim((string) ($bewerbung['vorname'] ?? '').' '.(string) ($bewerbung['name'] ?? '')),
            'E-Mail: '.(string) ($bewerbung['email'] ?? ''),
            'Status: '.(string) ($bewerbung['status'] ?? ''),
            'Schwerbehinderung: '.((bool) ($bewerbung['behindert'] ?? false) ? 'ja' : 'nein'),
            'Gefunden über: '.(string) ($bewerbung['wogefunden'] ?? ''),
            'Eingegangen: '.(string) ($bewerbung['created_at'] ?? ''),
        ];

        $ki = $bewerbung['ki'] ?? null;
        if (is_array($ki)) {
            $lines[] = '';
            $lines[] = 'KI-Auswertung:';
            $eintraege = array_merge(
                is_array($ki['fields'] ?? null) ? $ki['fields'] : [],
                is_array($ki['system'] ?? null) ? $ki['system'] : [],
            );
            foreach ($eintraege as $eintrag) {
                if (! is_array($eintrag)) {
                    continue;
                }
                $label = (string) ($eintrag['label'] ?? $eintrag['key'] ?? '');
                if ($label === '') {
                    continue;
                }
                $lines[] = $label.': '.self::value($eintrag['value'] ?? null);
            }
            if (isset($ki['status'])) {
                $lines[] = 'Auswertungsstatus: '.self::value($ki['status']);
            }
        }

        $lines[] = '';
        $lines[] = 'Hochgeladene Dateien:';
        $lines[] = $hochgeladen === [] ? '-' : '- '.implode("\n- ", $hochgeladen);
        $lines[] = '';
        $lines[] = 'Nicht übernommen (Format wird von LightRAG nicht gelesen):';
        $lines[] = $ausgelassen === [] ? '-' : '- '.implode("\n- ", $ausgelassen);

        return implode("\n", $lines);
    }

    public static function fileSource(int $bewerbungId): string
    {
        return 'bewerbung-'.$bewerbungId.'.txt';
    }

    private static function value(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(strval(...), $value));
        }

        if (is_bool($value)) {
            return $value ? 'ja' : 'nein';
        }

        return trim((string) $value);
    }
}
