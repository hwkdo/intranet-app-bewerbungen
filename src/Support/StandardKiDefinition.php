<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Support;

class StandardKiDefinition
{
    public const NAME = 'Standard (Azubi IT)';

    public static function instruktionen(): string
    {
        return <<<'INSTRUCTIONS'
Du bist ein Experte für die Analyse von Bewerbungsunterlagen. Deine Aufgabe ist es,
strukturierte Informationen aus Bewerbungsdokumenten (Anschreiben, Lebenslauf, Zeugnisse)
präzise zu extrahieren.

Wichtige Hinweise:
- Extrahiere NUR Informationen, die explizit in den Dokumenten vorhanden sind.
- Wenn eine Information nicht vorhanden ist, gib null oder ein leeres Array zurück.
- "wohnort" ist der aktuelle Wohnort des Bewerbers (Ort, möglichst mit PLZ), nicht frühere Wohnorte.
- "durchschnittsnote" ist der Notendurchschnitt als Dezimalzahl (z.B. 2.3).
- "fuehrerschein" ist true wenn explizit erwähnt, false wenn explizit verneint, null wenn keine Angabe.
- "fehlstunden" und "fehlstunden_unentschuldigt" sind Ganzzahlen aus dem letzten relevanten Zeugnis.
- "berufserfahrung_fachspezifisch" bezieht sich auf IT/Informatik-relevante Erfahrungen.
- Für "auffaelligkeiten" sind nur die vorgegebenen Kategorien erlaubt.
- "verarbeitete_zeugnisse" soll die Namen der erkannten Zeugnisdokumente enthalten.
INSTRUCTIONS;
    }

    /**
     * @return list<array{key: string, label: string, typ: string, hinweis: string, pflicht: bool, optionen: list<string>}>
     */
    public static function felder(): array
    {
        return [
            self::feld('nachname', 'Nachname', 'text', 'Nachname des Bewerbers', true),
            self::feld('vorname', 'Vorname', 'text', 'Vorname des Bewerbers', true),
            self::feld('wohnort', 'Wohnort', 'text', 'Aktueller Wohnort des Bewerbers aus Anschreiben oder Lebenslauf (Ort, möglichst mit PLZ). Frühere Wohnorte nicht übernehmen. null, wenn nicht erkennbar.'),
            self::feld('hoechster_schulabschluss', 'Höchster Schulabschluss', 'text', 'z.B. Abitur, Fachabitur, Mittlere Reife, Hauptschulabschluss'),
            self::feld('durchschnittsnote', 'Durchschnittsnote', 'zahl', 'Notendurchschnitt aus dem letzten relevanten Zeugnis als Dezimalzahl, null wenn nicht erkennbar'),
            self::feld('berufserfahrung_fachspezifisch', 'Berufserfahrung (fachspezifisch)', 'text', 'Kurze Beschreibung der IT/Informatik-relevanten Berufserfahrung, "keine" wenn nicht vorhanden'),
            self::feld('fuehrerschein', 'Führerschein', 'ja_nein', 'true = vorhanden, false = explizit nicht, null = keine Angabe'),
            self::feld('it_kenntnisse', 'IT-Kenntnisse', 'liste', 'Liste der erwähnten IT-Kenntnisse, Programme, Programmiersprachen'),
            self::feld('letzte_schulform', 'Letzte Schulform', 'text', 'z.B. Berufsschule, Gymnasium, Gesamtschule, Fachoberschule, Berufskolleg'),
            self::feld('luecken_im_lebenslauf', 'Lücken im Lebenslauf', 'liste', 'Zeiträume ohne Beschäftigung oder Ausbildung, z.B. "01/2020 - 06/2020"'),
            self::feld('fehlstunden', 'Fehlstunden', 'ganzzahl', 'Gesamtzahl Fehlstunden aus dem letzten Schulzeugnis. Suche nach Fehlstunden, Fehlzeiten, versäumte Stunden, Versäumnisse. Explizit genannte 0 übernehmen, sonst null.'),
            self::feld('fehlstunden_unentschuldigt', 'Fehlstunden unentschuldigt', 'ganzzahl', 'Davon unentschuldigte Fehlstunden. null, wenn nur eine Gesamtzahl oder keine Angabe vorhanden ist. Explizit genannte 0 übernehmen.'),
            self::feld('auffaelligkeiten', 'Auffälligkeiten', 'liste', 'Nur diese Kategorien: "Fehlende Zeugnisse", "Abgebrochene Ausbildung: [Details]", "Abgebrochenes Studium: [Details]", "Ausländische Zeugnisse", "Deutschkenntnisse: [Niveau]", "Besonders schlechte Noten: [Details]" (schlechter als 4), "ITA-Ausbildung: Schulische Ausbildung zum Informationstechnischen Assistenten"'),
            self::feld('verarbeitete_zeugnisse', 'Verarbeitete Zeugnisse', 'liste', 'Namen der erkannten und analysierten Zeugnis-Dokumente'),
        ];
    }

    /**
     * @return array{key: string, label: string, typ: string, hinweis: string, pflicht: bool, optionen: list<string>}
     */
    private static function feld(string $key, string $label, string $typ, string $hinweis, bool $pflicht = false): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'typ' => $typ,
            'hinweis' => $hinweis,
            'pflicht' => $pflicht,
            'optionen' => [],
        ];
    }
}
