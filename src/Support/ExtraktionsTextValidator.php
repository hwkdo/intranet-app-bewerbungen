<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Support;

final class ExtraktionsTextValidator
{
    public static function bewerten(string $text): ExtraktionsTextBewertung
    {
        $text = trim($text);

        if ($text === '') {
            return new ExtraktionsTextBewertung(
                istPlausibel: false,
                beschreibung: 'Leer – kein extrahierbarer Inhalt.',
                bedeutungsAnteil: 0.0,
                zeichenOhneLeerzeichen: 0,
            );
        }

        $ohneLeer = preg_replace('/\s+/u', '', $text) ?? '';
        $nonWsLen = mb_strlen($ohneLeer);

        if ($nonWsLen === 0) {
            return new ExtraktionsTextBewertung(
                istPlausibel: false,
                beschreibung: 'Nur Leerzeichen – kein nutzbarer Inhalt.',
                bedeutungsAnteil: 0.0,
                zeichenOhneLeerzeichen: 0,
            );
        }

        preg_match_all('/\p{L}/u', $text, $buchstabenTreffer);
        preg_match_all('/\p{N}/u', $text, $ziffernTreffer);
        $bedeutungsZeichen = count($buchstabenTreffer[0]) + count($ziffernTreffer[0]);
        $anteil = $bedeutungsZeichen / $nonWsLen;

        $ersatzZeichen = substr_count($text, "\u{FFFD}");
        $textLaenge = max(mb_strlen($text), 1);
        $ersatzAnteil = $ersatzZeichen / $textLaenge;

        if ($ersatzZeichen >= 3 && $ersatzAnteil >= 0.01) {
            return new ExtraktionsTextBewertung(
                istPlausibel: false,
                beschreibung: 'Viele Ersatzzeichen (U+FFFD) – vermutlich defekte Kodierung oder Extraktionsfehler.',
                bedeutungsAnteil: round($anteil, 3),
                zeichenOhneLeerzeichen: $nonWsLen,
            );
        }

        $plausibel = self::istPlausibelNachHeuristik($nonWsLen, $anteil);

        $beschreibung = $plausibel
            ? sprintf('Plausibel (%.0f %% Buchstaben/Ziffern, %d Zeichen ohne Leerzeichen).', $anteil * 100, $nonWsLen)
            : sprintf('Unplausibel (%.0f %% Buchstaben/Ziffern bei %d Zeichen ohne Leerzeichen) – vermutlich kein lesbarer Textlayer oder nur Symbolsalat.', $anteil * 100, $nonWsLen);

        return new ExtraktionsTextBewertung(
            istPlausibel: $plausibel,
            beschreibung: $beschreibung,
            bedeutungsAnteil: round($anteil, 3),
            zeichenOhneLeerzeichen: $nonWsLen,
        );
    }

    private static function istPlausibelNachHeuristik(int $nonWsLen, float $anteil): bool
    {
        if ($nonWsLen < 12) {
            return $anteil >= 0.55;
        }

        if ($nonWsLen < 35) {
            return $anteil >= 0.38;
        }

        if ($nonWsLen < 100) {
            return $anteil >= 0.28;
        }

        return $anteil >= 0.20;
    }
}

