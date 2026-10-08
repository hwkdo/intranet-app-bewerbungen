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
            foreach ([
                'vorname' => 'Vorname',
                'nachname' => 'Nachname',
                'hoechster_schulabschluss' => 'Höchster Schulabschluss',
                'durchschnittsnote' => 'Durchschnittsnote',
                'berufserfahrung_fachspezifisch' => 'Berufserfahrung',
                'fuehrerschein' => 'Führerschein',
                'it_kenntnisse' => 'IT-Kenntnisse',
                'letzte_schulform' => 'Letzte Schulform',
                'luecken_im_lebenslauf' => 'Lücken im Lebenslauf',
                'fehlstunden' => 'Fehlstunden',
                'fehlstunden_unentschuldigt' => 'Fehlstunden unentschuldigt',
                'auffaelligkeiten' => 'Auffälligkeiten',
                'verarbeitete_zeugnisse' => 'Verarbeitete Zeugnisse',
                'status' => 'Auswertungsstatus',
            ] as $key => $label) {
                $lines[] = $label.': '.self::value($ki[$key] ?? null);
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
