<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Console\Commands;

use Hwkdo\IntranetAppBase\Contracts\DocumentParseConfigResolverInterface;
use Hwkdo\IntranetAppBase\Enums\DocumentParseEngine;
use Hwkdo\IntranetAppBewerbungen\Data\AppSettings;
use Hwkdo\IntranetAppBewerbungen\Enums\BewerbungenAuswertungAiProvider;
use Hwkdo\IntranetAppBewerbungen\Models\IntranetAppBewerbungenSettings;
use Hwkdo\IntranetAppBewerbungen\Services\BewerbungAnalyseService;
use Hwkdo\IntranetAppBewerbungen\Support\KiDefinitionResolver;
use Hwkdo\LlamaParseLaravel\LlamaParse;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class BewerbungenAuswertenAiCommand extends Command
{
    protected $signature = 'bewerbungen:auswerten-ai
                            {json-datei : Pfad zur JSON-Datei mit den Bewerbungsdaten}
                            {--ausgabe= : Basisname für die Ausgabedateien ohne Endung (Standard: bewerbungen_auswertung_ai_<timestamp>)}
                            {--id= : Nur eine bestimmte Bewerbungs-ID verarbeiten}
                            {--definition= : ID der KI-Definition (leer = Standard-Definition)}
                            {--modell= : KI-Modell (überschreibt App-Einstellungen und config/ai.php)}
                            {--ohne-zwischenspeicher : Dateien nicht aus dem lokalen Download-Cache laden (Download erfolgt trotzdem und aktualisiert den Cache)}';

    protected $description = 'Wertet Bewerbungen mit laravel/ai aus. Dokumente liest das KI-Gateway, die Feldstruktur kommt aus der gewählten KI-Definition.';

    public function __construct(
        private readonly BewerbungAnalyseService $analyse,
        private readonly KiDefinitionResolver $definitionen,
        private readonly DocumentParseConfigResolverInterface $parseConfigResolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $jsonDatei = $this->argument('json-datei');
        if (! file_exists($jsonDatei)) {
            $this->error("JSON-Datei nicht gefunden: {$jsonDatei}");

            return self::FAILURE;
        }

        $bewerbungen = json_decode((string) file_get_contents($jsonDatei), true);
        if (! is_array($bewerbungen) || $bewerbungen === []) {
            $this->error('Ungültiges oder leeres JSON in der Datei.');

            return self::FAILURE;
        }

        if ($filterId = $this->option('id')) {
            if (! isset($bewerbungen[$filterId])) {
                $this->error("Bewerbungs-ID {$filterId} nicht gefunden.");

                return self::FAILURE;
            }
            $bewerbungen = [$filterId => $bewerbungen[$filterId]];
        }

        $parseConfig = $this->parseConfigResolver->resolve('bewerbungen');
        if ($parseConfig->engine === DocumentParseEngine::LlamaParse && ! app(LlamaParse::class)->configured()) {
            $this->error('LlamaParse ist nicht konfiguriert. LLAMA_CLOUD_API_KEY fehlt.');

            return self::FAILURE;
        }

        $definitionOption = $this->option('definition');
        $definitionId = is_string($definitionOption) && trim($definitionOption) !== '' ? (int) $definitionOption : null;

        try {
            $aufgeloest = $this->definitionen->resolve($definitionId);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $cliModell = $this->option('modell') ?: null;
        $appSettings = IntranetAppBewerbungenSettings::resolvedAppSettings();
        $auswertungModell = $this->resolveAuswertungModell(is_string($cliModell) ? $cliModell : null, $appSettings);
        $kiProvider = $appSettings->bewerbungenAuswertungAiProvider;
        $basisname = $this->option('ausgabe') ?? 'bewerbungen_auswertung_ai_'.now()->format('Y-m-d_His');

        $this->info('=== Bewerbungen Auswertung (laravel/ai) ===');
        $this->info('Verarbeite '.count($bewerbungen).' Bewerbung(en)');
        $this->info('Definition: '.$aufgeloest['definition']->name.($aufgeloest['fallback'] ? ' (Fallback auf Standard)' : ''));
        $this->info('KI-Provider: '.BewerbungenAuswertungAiProvider::options()[$kiProvider->value].' ('.$kiProvider->value.')');
        $this->info('Modell: '.($auswertungModell ?? $this->fallbackModell($kiProvider)));
        $this->newLine();

        $ergebnisse = [];
        $gesamtAnzahl = count($bewerbungen);
        $aktuell = 0;

        foreach ($bewerbungen as $id => $links) {
            $aktuell++;
            $this->statusZeile("<fg=cyan>[{$aktuell}/{$gesamtAnzahl}]</> Bewerbung <fg=yellow>ID {$id}</> – Start");

            try {
                $ergebnis = $this->analyse->analysiereLinks(
                    is_array($links) ? $links : [],
                    $aufgeloest['definition'],
                    $aufgeloest['fallback'],
                    $auswertungModell,
                    (bool) $this->option('ohne-zwischenspeicher'),
                    fn (string $nachricht) => $this->statusZeile($nachricht),
                );
                $ergebnis['id'] = $id;
                $ergebnis['fehler'] = '';
                $ergebnisse[] = $ergebnis;
                $name = $this->feldWert($ergebnis, 'vorname').' '.$this->feldWert($ergebnis, 'nachname');
                $this->statusZeile('  <fg=green>✓</> Bewerbung ID '.$id.' abgeschlossen: <fg=white>'.trim($name).'</>');
            } catch (\Throwable $e) {
                $this->statusZeile('  <fg=red>✗</> Fehlgeschlagen: '.$e->getMessage());
                $ergebnisse[] = [
                    'id' => $id,
                    'fields' => [],
                    'system' => [],
                    'fehler' => $e->getMessage(),
                ];
            }

            $this->newLine();
        }

        $spalten = $this->spaltenFuer($ergebnisse);
        $csvPfad = $basisname.'.csv';
        $xlsxPfad = $basisname.'.xlsx';
        $this->schreibeCsv($ergebnisse, $spalten, $csvPfad);
        $this->info("<fg=green>CSV:</> {$csvPfad}");
        $this->schreibeXlsx($ergebnisse, $spalten, $xlsxPfad);
        $this->info("<fg=green>Excel:</> {$xlsxPfad}");

        $erfolgreich = count(array_filter($ergebnisse, fn (array $e): bool => ($e['fehler'] ?? '') === ''));
        $this->newLine();
        $this->info("Abgeschlossen: {$erfolgreich} von {$gesamtAnzahl} Bewerbungen erfolgreich ausgewertet.");

        return self::SUCCESS;
    }

    private function resolveAuswertungModell(?string $cliModell, AppSettings $appSettings): ?string
    {
        if ($cliModell !== null && trim($cliModell) !== '') {
            return trim($cliModell);
        }

        $legacy = match ($appSettings->bewerbungenAuswertungAiProvider) {
            BewerbungenAuswertungAiProvider::GemmaLlamaCpp => $appSettings->bewerbungenAuswertungModelGemmaLlamaCpp,
            BewerbungenAuswertungAiProvider::OpenWebUi => $appSettings->bewerbungenAuswertungModelOpenWebUi,
            BewerbungenAuswertungAiProvider::Langdock => $appSettings->bewerbungenAuswertungModelLangdock,
        };

        $legacy = trim($legacy);

        return $legacy === '' ? null : $legacy;
    }

    private function fallbackModell(BewerbungenAuswertungAiProvider $provider): string
    {
        return match ($provider) {
            BewerbungenAuswertungAiProvider::GemmaLlamaCpp => (string) config('ai.providers.gemma-llama-cpp.models.text.default'),
            BewerbungenAuswertungAiProvider::OpenWebUi => (string) config('ai.providers.openwebui.models.text.default'),
            BewerbungenAuswertungAiProvider::Langdock => (string) config('ai.providers.langdock.models.text.default'),
        };
    }

    private function statusZeile(string $nachricht): void
    {
        $this->line($nachricht);
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
    }

    /**
     * @param  array<string, mixed>  $ergebnis
     */
    private function feldWert(array $ergebnis, string $key): string
    {
        foreach ($ergebnis['fields'] ?? [] as $feld) {
            if (is_array($feld) && ($feld['key'] ?? null) === $key) {
                return trim((string) ($feld['value'] ?? ''));
            }
        }

        return '';
    }

    /**
     * @param  list<array<string, mixed>>  $ergebnisse
     * @return list<array{quelle: string, key: string, label: string}>
     */
    private function spaltenFuer(array $ergebnisse): array
    {
        $spalten = [
            ['quelle' => 'id', 'key' => 'id', 'label' => 'ID'],
        ];
        $gesehen = ['id' => true];

        foreach (['fields', 'system'] as $quelle) {
            foreach ($ergebnisse as $ergebnis) {
                foreach ($ergebnis[$quelle] ?? [] as $eintrag) {
                    if (! is_array($eintrag)) {
                        continue;
                    }
                    $key = (string) ($eintrag['key'] ?? '');
                    if ($key === '' || isset($gesehen[$key])) {
                        continue;
                    }
                    $gesehen[$key] = true;
                    $spalten[] = [
                        'quelle' => $quelle,
                        'key' => $key,
                        'label' => (string) ($eintrag['label'] ?? $key),
                    ];
                }
            }
        }

        $spalten[] = ['quelle' => 'fehler', 'key' => 'fehler', 'label' => 'Fehler'];

        return $spalten;
    }

    /**
     * @param  array<string, mixed>  $ergebnis
     * @param  list<array{quelle: string, key: string, label: string}>  $spalten
     * @return list<string>
     */
    private function ergebnisZuZeile(array $ergebnis, array $spalten): array
    {
        $werte = [];
        foreach ($spalten as $spalte) {
            $werte[] = $this->zelle($ergebnis, $spalte);
        }

        return $werte;
    }

    /**
     * @param  array<string, mixed>  $ergebnis
     * @param  array{quelle: string, key: string, label: string}  $spalte
     */
    private function zelle(array $ergebnis, array $spalte): string
    {
        if ($spalte['quelle'] === 'id') {
            return (string) ($ergebnis['id'] ?? '');
        }
        if ($spalte['quelle'] === 'fehler') {
            return (string) ($ergebnis['fehler'] ?? '');
        }

        foreach ($ergebnis[$spalte['quelle']] ?? [] as $eintrag) {
            if (! is_array($eintrag) || ($eintrag['key'] ?? null) !== $spalte['key']) {
                continue;
            }

            return $this->formatWert($eintrag['value'] ?? null);
        }

        return '';
    }

    private function formatWert(mixed $wert): string
    {
        if (is_array($wert)) {
            return implode(', ', array_map(strval(...), $wert));
        }
        if ($wert === true) {
            return 'Ja';
        }
        if ($wert === false) {
            return 'Nein';
        }

        return (string) ($wert ?? '');
    }

    /**
     * @param  list<array<string, mixed>>  $ergebnisse
     * @param  list<array{quelle: string, key: string, label: string}>  $spalten
     */
    private function schreibeCsv(array $ergebnisse, array $spalten, string $ausgabePfad): void
    {
        $handle = fopen($ausgabePfad, 'w');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, array_column($spalten, 'label'), ';');
        foreach ($ergebnisse as $ergebnis) {
            fputcsv($handle, $this->ergebnisZuZeile($ergebnis, $spalten), ';');
        }
        fclose($handle);
    }

    /**
     * @param  list<array<string, mixed>>  $ergebnisse
     * @param  list<array{quelle: string, key: string, label: string}>  $spalten
     */
    private function schreibeXlsx(array $ergebnisse, array $spalten, string $ausgabePfad): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Bewerbungen (AI)');

        foreach ($spalten as $index => $spalte) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).'1', $spalte['label']);
        }

        $letzteCol = Coordinate::stringFromColumnIndex(count($spalten));
        $sheet->getStyle("A1:{$letzteCol}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E79']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
        ]);

        foreach ($ergebnisse as $zeilenIndex => $ergebnis) {
            $excelZeile = $zeilenIndex + 2;
            foreach ($this->ergebnisZuZeile($ergebnis, $spalten) as $colIndex => $wert) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIndex + 1).$excelZeile, $wert);
            }

            $this->setzeLinkSpalten($sheet, $spalten, $ergebnis, $excelZeile);

            $hintergrund = $zeilenIndex % 2 === 0 ? 'FFFFFF' : 'DCE6F1';
            $sheet->getStyle("A{$excelZeile}:{$letzteCol}{$excelZeile}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $hintergrund]],
                'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BDD7EE']]],
            ]);

            if (($ergebnis['fehler'] ?? '') !== '') {
                $sheet->getStyle("A{$excelZeile}:{$letzteCol}{$excelZeile}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFCCCC']],
                ]);
            }
        }

        foreach ($spalten as $index => $spalte) {
            $breite = match ($spalte['key']) {
                'id' => 8,
                'bewerbung_ro', 'anhang_ro' => 45,
                default => 22,
            };
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($breite);
        }

        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$letzteCol}1");
        (new Xlsx($spreadsheet))->save($ausgabePfad);
    }

    /**
     * @param  list<array{quelle: string, key: string, label: string}>  $spalten
     * @param  array<string, mixed>  $ergebnis
     */
    private function setzeLinkSpalten(Worksheet $sheet, array $spalten, array $ergebnis, int $excelZeile): void
    {
        foreach ($spalten as $index => $spalte) {
            if ($spalte['quelle'] !== 'system') {
                continue;
            }
            $wert = $this->roherSystemWert($ergebnis, $spalte['key']);
            if (! is_string($wert) || (! str_starts_with($wert, 'http://') && ! str_starts_with($wert, 'https://'))) {
                continue;
            }
            $zelle = Coordinate::stringFromColumnIndex($index + 1).$excelZeile;
            $sheet->getCell($zelle)->getHyperlink()->setUrl($wert);
            $sheet->getStyle($zelle)->getFont()->getColor()->setRGB('0563C1');
            $sheet->getStyle($zelle)->getFont()->setUnderline(true);
        }
    }

    /**
     * @param  array<string, mixed>  $ergebnis
     */
    private function roherSystemWert(array $ergebnis, string $key): mixed
    {
        foreach ($ergebnis['system'] ?? [] as $eintrag) {
            if (is_array($eintrag) && ($eintrag['key'] ?? null) === $key) {
                return $eintrag['value'] ?? null;
            }
        }

        return null;
    }
}
