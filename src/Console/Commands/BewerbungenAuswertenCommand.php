<?php

namespace Hwkdo\IntranetAppBewerbungen\Console\Commands;

use Hwkdo\MsGraphLaravel\Interfaces\MsGraphShareServiceInterface;
use Hwkdo\OpenwebuiApiLaravel\Services\OpenWebUiRagService;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class BewerbungenAuswertenCommand extends Command
{
    protected $signature = 'bewerbungen:auswerten
                            {json-datei : Pfad zur JSON-Datei mit den Bewerbungsdaten}
                            {--ausgabe= : Basisname für die Ausgabedateien ohne Endung (Standard: bewerbungen_auswertung_<timestamp>)}
                            {--id= : Nur eine bestimmte Bewerbungs-ID verarbeiten}
                            {--modell= : OpenWebUI-Modell (überschreibt Konfiguration)}';

    protected $description = 'Wertet Bewerbungen aus OneDrive-Ordnern mit KI aus und erstellt CSV- und Excel-Ausgabe.';

    /** @var string[] */
    private const ERLAUBTE_ENDUNGEN = ['pdf', 'docx', 'doc', 'jpg', 'jpeg', 'png'];

    public function __construct(
        private readonly MsGraphShareServiceInterface $shareService,
        private readonly OpenWebUiRagService $ragService,
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

        $bewerbungen = json_decode(file_get_contents($jsonDatei), true);

        if (! is_array($bewerbungen) || empty($bewerbungen)) {
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

        $modell = $this->option('modell') ?? config('openwebui-api-laravel.default_model');
        $basisname = $this->option('ausgabe') ?? 'bewerbungen_auswertung_'.now()->format('Y-m-d_His');
        $csvPfad = $basisname.'.csv';
        $xlsxPfad = $basisname.'.xlsx';

        $this->info('Verarbeite '.count($bewerbungen).' Bewerbung(en) mit Modell: '.$modell);
        $this->newLine();

        $ergebnisse = [];
        $fortschritt = $this->output->createProgressBar(count($bewerbungen));
        $fortschritt->start();

        foreach ($bewerbungen as $id => $links) {
            try {
                $ergebnis = $this->verarbeiteBewerbung((string) $id, $links, $modell);
                $ergebnis['id'] = $id;
                $ergebnisse[] = $ergebnis;
            } catch (\Throwable $e) {
                $this->newLine();
                $this->warn("  Bewerbung {$id} fehlgeschlagen: ".$e->getMessage());
                $ergebnisse[] = ['id' => $id, 'fehler' => $e->getMessage()];
            }

            $fortschritt->advance();
        }

        $fortschritt->finish();
        $this->newLine(2);

        $this->schreibeCsv($ergebnisse, $csvPfad);
        $this->info("CSV:   {$csvPfad}");

        $this->schreibeXlsx($ergebnisse, $xlsxPfad);
        $this->info("Excel: {$xlsxPfad}");

        return self::SUCCESS;
    }

    /**
     * @param  array{bewerbung_ro: string, anhang_ro?: string}  $links
     * @return array<string, mixed>
     */
    private function verarbeiteBewerbung(string $id, array $links, string $modell): array
    {
        $tempDateien = [];
        $openWebUiFileIds = [];

        try {
            $bewerbungDateien = $this->shareService->getSharedFolderContents($links['bewerbung_ro']);

            $anhangDateien = [];
            if (! empty($links['anhang_ro'])) {
                try {
                    $anhangDateien = $this->shareService->getSharedFolderContents($links['anhang_ro']);
                } catch (\Throwable) {
                }
            }

            $alleDateien = array_merge($bewerbungDateien, $anhangDateien);

            if (empty($alleDateien)) {
                throw new \Exception('Keine Dateien in den OneDrive-Ordnern gefunden.');
            }

            foreach ($alleDateien as $dateiItem) {
                $downloadUrl = $dateiItem['@microsoft.graph.downloadUrl'] ?? null;
                $dateiName = $dateiItem['name'] ?? 'unbekannt';

                if (! $downloadUrl || isset($dateiItem['folder'])) {
                    continue;
                }

                $ext = strtolower(pathinfo($dateiName, PATHINFO_EXTENSION));
                if (! in_array($ext, self::ERLAUBTE_ENDUNGEN, true)) {
                    continue;
                }

                $inhalt = $this->shareService->downloadDriveItemContent($downloadUrl);

                $tempPfad = sys_get_temp_dir()."/bewerbung_{$id}_".uniqid().'.'.$ext;
                file_put_contents($tempPfad, $inhalt);
                $tempDateien[] = $tempPfad;

                $uploadErgebnis = $this->ragService->uploadFile($tempPfad, true, true);
                $fileId = $uploadErgebnis['id'] ?? null;

                if ($fileId) {
                    try {
                        $this->ragService->waitForFileProcessing($fileId, 120);
                    } catch (\Throwable) {
                    }
                    $openWebUiFileIds[] = $fileId;
                }
            }

            if (empty($openWebUiFileIds)) {
                throw new \Exception('Keine unterstützten Dateien zum Hochladen gefunden.');
            }

            return $this->auswertungMitKI($openWebUiFileIds, $modell);
        } finally {
            foreach ($tempDateien as $tempPfad) {
                if (file_exists($tempPfad)) {
                    unlink($tempPfad);
                }
            }
            foreach ($openWebUiFileIds as $fileId) {
                try {
                    $this->ragService->deleteFile($fileId);
                } catch (\Throwable) {
                }
            }
        }
    }

    /**
     * @param  string[]  $fileIds
     * @return array<string, mixed>
     */
    private function auswertungMitKI(array $fileIds, string $modell): array
    {
        $antwort = $this->ragService->chatWithFiles($modell, [
            ['role' => 'user', 'content' => $this->erstelleExtraktionsPrompt()],
        ], $fileIds);

        $inhalt = $antwort['choices'][0]['message']['content'] ?? '';

        return $this->parseKiAntwort($inhalt);
    }

    private function erstelleExtraktionsPrompt(): string
    {
        return <<<'PROMPT'
Analysiere die hochgeladenen Bewerbungsdokumente (Anschreiben, Lebenslauf und ggf. Zeugnisse/Zertifikate) und extrahiere die folgenden Informationen als strukturiertes JSON.

Gib NUR das JSON zurück, ohne weitere Erklärungen oder Markdown-Formatierung.

Format:
{
    "nachname": "...",
    "vorname": "...",
    "hoechster_schulabschluss": "...",
    "durchschnittsnote": null,
    "berufserfahrung_fachspezifisch": "...",
    "fuehrerschein": null,
    "it_kenntnisse": [],
    "letzte_schulform": "...",
    "luecken_im_lebenslauf": [],
    "fehlstunden": null,
    "fehlstunden_unentschuldigt": null,
    "auffaelligkeiten": []
}
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseKiAntwort(string $inhalt): array
    {
        $inhalt = trim($inhalt);
        $inhalt = preg_replace('/^```(?:json)?\s*/m', '', $inhalt) ?? $inhalt;
        $inhalt = preg_replace('/```\s*$/m', '', $inhalt) ?? $inhalt;
        $daten = json_decode(trim($inhalt), true);

        if (! is_array($daten)) {
            return ['rohantwort' => $inhalt, 'fehler' => 'KI-Antwort konnte nicht als JSON geparst werden'];
        }

        return $daten;
    }

    /** @var array<string, string> */
    private const SPALTEN = [
        'id' => 'ID',
        'nachname' => 'Nachname',
        'vorname' => 'Vorname',
        'hoechster_schulabschluss' => 'Höchster Schulabschluss',
        'durchschnittsnote' => 'Durchschnittsnote',
        'berufserfahrung_fachspezifisch' => 'Berufserfahrung (fachspezifisch)',
        'fuehrerschein' => 'Führerschein',
        'it_kenntnisse' => 'IT-Kenntnisse',
        'letzte_schulform' => 'Letzte Schulform',
        'luecken_im_lebenslauf' => 'Lücken im Lebenslauf',
        'fehlstunden' => 'Fehlstunden',
        'fehlstunden_unentschuldigt' => 'Fehlstunden unentschuldigt',
        'auffaelligkeiten' => 'Auffälligkeiten',
        'fehler' => 'Fehler',
        'rohantwort' => 'Rohantwort KI',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $ergebnisse
     */
    private function schreibeCsv(array $ergebnisse, string $ausgabePfad): void
    {
        $handle = fopen($ausgabePfad, 'w');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, array_values(self::SPALTEN), ';');

        foreach ($ergebnisse as $ergebnis) {
            fputcsv($handle, $this->ergebnisZuZeile($ergebnis), ';');
        }

        fclose($handle);
    }

    /**
     * @param  array<int, array<string, mixed>>  $ergebnisse
     */
    private function schreibeXlsx(array $ergebnisse, string $ausgabePfad): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Bewerbungen');

        $spaltenBuchstaben = range('A', 'Z');
        $felder = array_keys(self::SPALTEN);
        $ueberschriften = array_values(self::SPALTEN);

        foreach ($ueberschriften as $index => $titel) {
            $col = $spaltenBuchstaben[$index];
            $sheet->setCellValue("{$col}1", $titel);
        }

        $letzteCol = $spaltenBuchstaben[count($felder) - 1];
        $sheet->getStyle("A1:{$letzteCol}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E79']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
        ]);

        foreach ($ergebnisse as $zeilenIndex => $ergebnis) {
            $excelZeile = $zeilenIndex + 2;
            $zeilenwerte = $this->ergebnisZuZeile($ergebnis);

            foreach ($zeilenwerte as $colIndex => $wert) {
                $col = $spaltenBuchstaben[$colIndex];
                $sheet->setCellValue("{$col}{$excelZeile}", $wert);
            }

            $hintergrund = $zeilenIndex % 2 === 0 ? 'FFFFFF' : 'DCE6F1';
            $sheet->getStyle("A{$excelZeile}:{$letzteCol}{$excelZeile}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $hintergrund]],
                'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BDD7EE']]],
            ]);

            if (! empty($ergebnis['fehler'])) {
                $sheet->getStyle("A{$excelZeile}:{$letzteCol}{$excelZeile}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFCCCC']],
                ]);
            }
        }

        $breiten = [
            'id' => 8, 'nachname' => 20, 'vorname' => 18,
            'hoechster_schulabschluss' => 22, 'durchschnittsnote' => 18,
            'berufserfahrung_fachspezifisch' => 40, 'fuehrerschein' => 14,
            'it_kenntnisse' => 30, 'letzte_schulform' => 20,
            'luecken_im_lebenslauf' => 30, 'fehlstunden' => 14,
            'fehlstunden_unentschuldigt' => 22, 'auffaelligkeiten' => 40,
            'fehler' => 30, 'rohantwort' => 50,
        ];
        foreach ($felder as $index => $feld) {
            $col = $spaltenBuchstaben[$index];
            $sheet->getColumnDimension($col)->setWidth($breiten[$feld] ?? 20);
        }

        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$letzteCol}1");

        $writer = new Xlsx($spreadsheet);
        $writer->save($ausgabePfad);
    }

    /**
     * @param  array<string, mixed>  $ergebnis
     * @return string[]
     */
    private function ergebnisZuZeile(array $ergebnis): array
    {
        $zeile = [];
        foreach (array_keys(self::SPALTEN) as $feld) {
            $wert = $ergebnis[$feld] ?? '';
            if (is_array($wert)) {
                $wert = implode(', ', $wert);
            } elseif ($wert === true) {
                $wert = 'Ja';
            } elseif ($wert === false) {
                $wert = 'Nein';
            }
            $zeile[] = (string) $wert;
        }

        return $zeile;
    }
}

