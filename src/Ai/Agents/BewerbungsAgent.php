<?php

namespace Hwkdo\IntranetAppBewerbungen\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class BewerbungsAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function provider(): string
    {
        return 'openwebui';
    }

    public function timeout(): int
    {
        return 300;
    }

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
Du bist ein Experte für die Analyse von Bewerbungsunterlagen. Deine Aufgabe ist es,
strukturierte Informationen aus Bewerbungsdokumenten (Anschreiben, Lebenslauf, Zeugnisse)
präzise zu extrahieren.

Wichtige Hinweise:
- Extrahiere NUR Informationen, die explizit in den Dokumenten vorhanden sind.
- Wenn eine Information nicht vorhanden ist, gib null oder ein leeres Array zurück.
- "durchschnittsnote" ist der Notendurchschnitt als Dezimalzahl (z.B. 2.3).
- "fuehrerschein" ist true wenn explizit erwähnt, false wenn explizit verneint, null wenn keine Angabe.
- "fehlstunden" und "fehlstunden_unentschuldigt" sind Ganzzahlen aus dem letzten relevanten Zeugnis.
- "berufserfahrung_fachspezifisch" bezieht sich auf IT/Informatik-relevante Erfahrungen.
- Für "auffaelligkeiten" sind nur die vorgegebenen Kategorien erlaubt.
- "verarbeitete_zeugnisse" soll die Namen der erkannten Zeugnisdokumente enthalten.
INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'nachname' => $schema->string()->required()
                ->description('Nachname des Bewerbers'),
            'vorname' => $schema->string()->required()
                ->description('Vorname des Bewerbers'),
            'hoechster_schulabschluss' => $schema->string()->nullable()
                ->description('z.B. Abitur, Fachabitur, Mittlere Reife, Hauptschulabschluss'),
            'durchschnittsnote' => $schema->number()->nullable()
                ->description('Notendurchschnitt als Dezimalzahl, null wenn nicht erkennbar'),
            'berufserfahrung_fachspezifisch' => $schema->string()->nullable()
                ->description('Kurze Beschreibung der IT/fachspezifischen Berufserfahrung, "keine" wenn nicht vorhanden'),
            'fuehrerschein' => $schema->boolean()->nullable()
                ->description('true = vorhanden, false = explizit nicht, null = keine Angabe'),
            'it_kenntnisse' => $schema->array()->items($schema->string())
                ->description('Liste der erwähnten IT-Kenntnisse, Programme, Sprachen'),
            'letzte_schulform' => $schema->string()->nullable()
                ->description('z.B. Berufsschule, Gymnasium, Gesamtschule, Fachoberschule, Berufskolleg'),
            'luecken_im_lebenslauf' => $schema->array()->items($schema->string())
                ->description('Zeiträume mit Lücken, z.B. "01/2020 - 06/2020"'),
            'fehlstunden' => $schema->integer()->nullable()
                ->description('Gesamtzahl Fehlstunden aus Zeugnis, null wenn nicht vorhanden'),
            'fehlstunden_unentschuldigt' => $schema->integer()->nullable()
                ->description('Davon unentschuldigte Fehlstunden, null wenn nicht vorhanden'),
            'auffaelligkeiten' => $schema->array()->items($schema->string())
                ->description('Nur: Fehlende Zeugnisse | Abgebrochene Ausbildung: [Details] | Abgebrochenes Studium: [Details] | Ausländische Zeugnisse | Deutschkenntnisse: [Niveau] | Besonders schlechte Noten: [Details] | ITA-Ausbildung: Schulische Ausbildung zum Informationstechnischen Assistenten'),
            'verarbeitete_zeugnisse' => $schema->array()->items($schema->string())
                ->description('Namen der erkannten und analysierten Zeugnis-Dokumente'),
        ];
    }
}

