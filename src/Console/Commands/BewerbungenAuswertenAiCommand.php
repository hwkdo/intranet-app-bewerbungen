<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Console\Commands;

use Hwkdo\IntranetAppBase\Contracts\DocumentParseConfigResolverInterface;
use Hwkdo\IntranetAppBase\Contracts\IntranetAiGatewayInterface;
use Hwkdo\IntranetAppBase\Data\AiRequestContext;
use Hwkdo\IntranetAppBase\Data\ResolvedDocumentParseConfig;
use Hwkdo\IntranetAppBase\Enums\AiCapability;
use Hwkdo\IntranetAppBase\Enums\AiProvider;
use Hwkdo\IntranetAppBase\Enums\DocumentParseEngine;
use Hwkdo\IntranetAppBewerbungen\Ai\Agents\BewerbungsAgent;
use Hwkdo\IntranetAppBewerbungen\Data\AppSettings;
use Hwkdo\IntranetAppBewerbungen\Enums\BewerbungenAuswertungAiProvider;
use Hwkdo\IntranetAppBewerbungen\Models\IntranetAppBewerbungenSettings;
use Hwkdo\IntranetAppBewerbungen\Support\ExtraktionsTextValidator;
use Hwkdo\LlamaParseLaravel\LlamaParse;
use Hwkdo\MsGraphLaravel\Interfaces\MsGraphShareServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
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
                            {--modell= : KI-Modell (überschreibt App-Einstellungen und config/ai.php)}
                            {--ohne-zwischenspeicher : Dateien nicht aus dem lokalen Download-Cache laden (Download erfolgt trotzdem und aktualisiert den Cache)}';

    protected $description = 'Wertet Bewerbungen mit laravel/ai aus. Dokumente liest das KI-Gateway, die Auswertung läuft standardmäßig über Gemma (llama.cpp).';

    /** @var string[] */
    private const ERLAUBTE_ENDUNGEN = [
        'pdf', 'doc', 'docx', 'txt', 'rtf', 'odt', 'ppt', 'pptx', 'xls', 'xlsx', 'csv',
        'html', 'htm', 'md', 'jpg', 'jpeg', 'png', 'tif', 'tiff', 'webp', 'bmp', 'heic', 'gif',
    ];

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
        'verarbeitete_zeugnisse' => 'Verarbeitete Zeugnisse',
        'bewerbung_ro' => 'OneDrive Link Bewerbung',
        'anhang_ro' => 'OneDrive Link Anhang',
        'fehler' => 'Fehler',
    ];

    private ?ResolvedDocumentParseConfig $parseConfig = null;

    public function __construct(
        private readonly MsGraphShareServiceInterface $shareService,
        private readonly IntranetAiGatewayInterface $aiGateway,
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

        $this->parseConfig = $this->parseConfigResolver->resolve('bewerbungen');
        if ($this->parseConfig->engine === DocumentParseEngine::LlamaParse && ! app(LlamaParse::class)->configured()) {
            $this->error('LlamaParse ist nicht konfiguriert. LLAMA_CLOUD_API_KEY fehlt.');

            return self::FAILURE;
        }

        File::ensureDirectoryExists($this->downloadCacheVerzeichnis());

        $cliModell = $this->option('modell') ?: null;
        $appSettings = IntranetAppBewerbungenSettings::resolvedAppSettings();
        $kiProvider = $appSettings->bewerbungenAuswertungAiProvider;
        $kiProviderLabel = BewerbungenAuswertungAiProvider::options()[$kiProvider->value];
        $auswertungModell = $this->resolveAuswertungModell($cliModell, $appSettings);
        $fallbackDefault = match ($kiProvider) {
            BewerbungenAuswertungAiProvider::GemmaLlamaCpp => (string) config('ai.providers.gemma-llama-cpp.models.text.default'),
            BewerbungenAuswertungAiProvider::OpenWebUi => (string) config('ai.providers.openwebui.models.text.default'),
            BewerbungenAuswertungAiProvider::Langdock => (string) config('ai.providers.langdock.models.text.default'),
        };
        $modellAnzeige = $auswertungModell ?? $fallbackDefault;
        $modellQuelle = match (true) {
            $cliModell !== null && trim((string) $cliModell) !== '' => 'CLI --modell',
            $auswertungModell !== null => 'App-Einstellungen',
            default => 'config/ai.php (Standard je Provider)',
        };
        $basisname = $this->option('ausgabe') ?? 'bewerbungen_auswertung_ai_'.now()->format('Y-m-d_His');
        $csvPfad = $basisname.'.csv';
        $xlsxPfad = $basisname.'.xlsx';

        $this->info('=== Bewerbungen Auswertung (laravel/ai) ===');
        $this->info('Verarbeite '.count($bewerbungen).' Bewerbung(en)');
        $this->info('KI-Provider: '.$kiProviderLabel.' ('.$kiProvider->value.')');
        $this->info('Modell: '.$modellAnzeige.' ('.$modellQuelle.')');
        $this->info('Download-Zwischenspeicher: '.$this->downloadCacheVerzeichnis());
        $this->newLine();

        $ergebnisse = [];
        $gesamtAnzahl = count($bewerbungen);
        $aktuell = 0;

        foreach ($bewerbungen as $id => $links) {
            $aktuell++;
            $this->statusZeile("<fg=cyan>[{$aktuell}/{$gesamtAnzahl}]</> Bewerbung <fg=yellow>ID {$id}</> – Start");

            try {
                $ergebnis = $this->verarbeiteBewerbung((string) $id, $links, $auswertungModell, $appSettings);
                $ergebnis['id'] = $id;
                $ergebnis['bewerbung_ro'] = $links['bewerbung_ro'] ?? '';
                $ergebnis['anhang_ro'] = $links['anhang_ro'] ?? '';
                $ergebnisse[] = $ergebnis;

                $name = trim(($ergebnis['vorname'] ?? '').' '.($ergebnis['nachname'] ?? ''));
                $this->statusZeile("  <fg=green>✓</> Bewerbung ID {$id} abgeschlossen: <fg=white>{$name}</>");
            } catch (\Throwable $e) {
                $this->statusZeile('  <fg=red>✗</> Fehlgeschlagen: '.$e->getMessage());
                $ergebnisse[] = [
                    'id' => $id,
                    'bewerbung_ro' => $links['bewerbung_ro'] ?? '',
                    'anhang_ro' => $links['anhang_ro'] ?? '',
                    'fehler' => $e->getMessage(),
                ];
            }

            $this->newLine();
        }

        $this->schreibeCsv($ergebnisse, $csvPfad);
        $this->info("<fg=green>CSV:</> {$csvPfad}");

        $this->schreibeXlsx($ergebnisse, $xlsxPfad);
        $this->info("<fg=green>Excel:</> {$xlsxPfad}");

        $erfolgreich = count(array_filter($ergebnisse, fn ($e) => empty($e['fehler'])));
        $this->newLine();
        $this->info("Abgeschlossen: {$erfolgreich} von {$gesamtAnzahl} Bewerbungen erfolgreich ausgewertet.");

        return self::SUCCESS;
    }

    /**
     * Reihenfolge: CLI `--modell` (wenn gesetzt), sonst App-Einstellungen je Provider, sonst `null` (= Laravel-AI-Default aus config/ai.php).
     */
    private function resolveAuswertungModell(?string $cliModell, AppSettings $appSettings): ?string
    {
        if ($cliModell !== null && trim($cliModell) !== '') {
            return trim($cliModell);
        }

        return match ($appSettings->bewerbungenAuswertungAiProvider) {
            BewerbungenAuswertungAiProvider::GemmaLlamaCpp => $this->trimmedNonEmpty($appSettings->bewerbungenAuswertungModelGemmaLlamaCpp),
            BewerbungenAuswertungAiProvider::OpenWebUi => $this->trimmedNonEmpty($appSettings->bewerbungenAuswertungModelOpenWebUi),
            BewerbungenAuswertungAiProvider::Langdock => $this->trimmedNonEmpty($appSettings->bewerbungenAuswertungModelLangdock),
        };
    }

    private function trimmedNonEmpty(string $value): ?string
    {
        $t = trim($value);

        return $t === '' ? null : $t;
    }

    private function downloadCacheVerzeichnis(): string
    {
        $relativ = (string) config('intranet-app-bewerbungen.ai.download_cache_path', 'bewerbungen_auswertung_cache');

        return storage_path('app/'.$relativ);
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
     * @param  array{bewerbung_ro: string, anhang_ro?: string}  $links
     * @return array<string, mixed>
     */
    private function verarbeiteBewerbung(string $id, array $links, ?string $modell, AppSettings $appSettings): array
    {
        $this->statusZeile('  <fg=blue>→</> Schritt 1/4: Warte auf API – Bewerbungsordner (Graph) …');
        $bewerbungDateien = $this->shareService->getSharedFolderContents($links['bewerbung_ro']);
        $this->statusZeile('  <fg=blue>  </> '.count($bewerbungDateien).' Datei(en) im Bewerbungsordner.');

        $anhangDateien = [];
        if (! empty($links['anhang_ro'])) {
            $this->statusZeile('  <fg=blue>→</> Schritt 2/4: Warte auf API – Anhangsordner (Graph) …');
            try {
                $anhangDateien = $this->shareService->getSharedFolderContents($links['anhang_ro']);
                $this->statusZeile('  <fg=blue>  </> '.count($anhangDateien).' Datei(en) im Anhangsordner.');
            } catch (\Throwable) {
                $this->statusZeile('  <fg=yellow>  ⚠</> Anhangsordner nicht abrufbar (optional, übersprungen).');
            }
        } else {
            $this->statusZeile('  <fg=blue>→</> Schritt 2/4: Kein Anhangsordner – übersprungen.');
        }

        $this->statusZeile('  <fg=blue>→</> Schritt 3/4: Dateien laden / aus Zwischenspeicher lesen und Text extrahieren …');

        $bewerbungTexte = $this->extrahiereDateiTexte($bewerbungDateien, $id, 'bewerbung');
        $anhangTexte = $this->extrahiereDateiTexte($anhangDateien, $id, 'anhang');

        $anhangNamen = array_keys($anhangTexte);
        $alleTexte = array_merge($bewerbungTexte, $anhangTexte);

        if (empty($alleTexte)) {
            throw new \Exception('Keine lesbaren Dateien. LlamaParse hat keinen verwendbaren Text geliefert.');
        }

        $this->statusZeile('  <fg=blue>→</> Schritt 4/4: Warte auf KI-Antwort (BewerbungsAgent) mit '.count($alleTexte).' Dokument(en) …');

        $ergebnis = $this->auswertungMitKi($alleTexte, $anhangNamen, $modell, $appSettings);
        $ergebnis['verarbeitete_zeugnisse'] = $ergebnis['verarbeitete_zeugnisse'] ?? $anhangNamen;

        $this->statusZeile('  <fg=green>  </> KI-Antwort erhalten.');

        if (! empty($ergebnis['verarbeitete_zeugnisse'])) {
            $this->statusZeile('  <fg=blue>  </> Zeugnisse: '.implode(', ', $ergebnis['verarbeitete_zeugnisse']));
        }

        return $ergebnis;
    }

    /**
     * @param  array<int, array<string, mixed>>  $dateiListe
     * @return array<string, string>
     */
    private function extrahiereDateiTexte(array $dateiListe, string $id, string $typ): array
    {
        $texte = [];
        $gesamt = count($dateiListe);
        $fortschrittDenominator = max($gesamt, 1);
        $index = 0;

        foreach ($dateiListe as $dateiItem) {
            $index++;
            $downloadUrl = $dateiItem['@microsoft.graph.downloadUrl'] ?? null;
            $dateiName = $dateiItem['name'] ?? 'unbekannt';

            if (! $downloadUrl || isset($dateiItem['folder'])) {
                continue;
            }

            $ext = strtolower(pathinfo($dateiName, PATHINFO_EXTENSION));

            if (! in_array($ext, self::ERLAUBTE_ENDUNGEN, true)) {
                $this->statusZeile("  <fg=yellow>  ⚠</> [{$index}/{$fortschrittDenominator}] Übersprungen (Format): {$dateiName}");

                continue;
            }

            $cachePfad = $this->cachePfadFuerDatei($id, $typ, $dateiItem, $ext);
            $nutzeCache = ! $this->option('ohne-zwischenspeicher') && is_file($cachePfad);

            try {
                if ($nutzeCache) {
                    $this->statusZeile("  <fg=magenta>    ⊙</> [{$index}/{$fortschrittDenominator}] {$dateiName} – aus Zwischenspeicher (kein Download)");
                    $lokalPfad = $cachePfad;
                } else {
                    $this->statusZeile("  <fg=blue>    ↓</> [{$index}/{$fortschrittDenominator}] {$dateiName} – Warte auf Download (Graph) …");
                    $inhalt = $this->shareService->downloadDriveItemContent($downloadUrl);
                    $this->atomarSchreiben($cachePfad, $inhalt);
                    $lokalPfad = $cachePfad;
                    $this->statusZeile("  <fg=blue>    </> [{$index}/{$fortschrittDenominator}] {$dateiName} – gespeichert unter Zwischenspeicher");
                }

                $text = $this->liesDokument($lokalPfad, $cachePfad, $dateiName, $index, $fortschrittDenominator);
                $bewertung = ExtraktionsTextValidator::bewerten($text);

                if ($bewertung->istPlausibel) {
                    $this->statusZeile("  <fg=green>    ℹ</> Qualität: {$bewertung->beschreibung}");
                } else {
                    $this->statusZeile("  <fg=yellow>    ℹ</> Qualität: {$bewertung->beschreibung}");
                }

                if ($bewertung->istPlausibel && trim($text) !== '') {
                    $zeichenAnzahl = strlen($text);
                    $this->statusZeile("  <fg=green>    ✓</> {$dateiName} ({$zeichenAnzahl} Zeichen, für KI verwendet)");
                    $texte[$dateiName] = $text;
                } else {
                    $this->statusZeile("  <fg=yellow>    ⚠</> {$dateiName} – nicht für KI verwendet (Extraktion unplausibel oder leer)");
                }
            } catch (\Throwable $e) {
                $this->statusZeile("  <fg=red>    ✗</> {$dateiName}: ".$e->getMessage());
            }
        }

        return $texte;
    }

    /**
     * @param  array<string, mixed>  $dateiItem
     */
    private function cachePfadFuerDatei(string $bewerbungId, string $typ, array $dateiItem, string $ext): string
    {
        $eTag = $dateiItem['eTag'] ?? $dateiItem['@odata.etag'] ?? '';
        $signatur = hash('sha256', implode('|', [
            $bewerbungId,
            $typ,
            (string) ($dateiItem['id'] ?? ''),
            (string) ($dateiItem['name'] ?? ''),
            (string) $eTag,
            (string) ($dateiItem['size'] ?? ''),
        ]));
        $sichereExt = preg_match('/^[a-z0-9]{1,8}$/', $ext) ? $ext : 'bin';

        return $this->downloadCacheVerzeichnis().'/'.$signatur.'.'.$sichereExt;
    }

    private function atomarSchreiben(string $zielPfad, string $inhalt): void
    {
        $verzeichnis = dirname($zielPfad);
        File::ensureDirectoryExists($verzeichnis);
        $tempPfad = $verzeichnis.'/'.uniqid('part_', true);
        file_put_contents($tempPfad, $inhalt);
        if (! rename($tempPfad, $zielPfad)) {
            @unlink($tempPfad);

            throw new \RuntimeException("Konnte Datei nicht nach {$zielPfad} schreiben.");
        }
    }

    private function liesDokument(
        string $lokalPfad,
        string $cachePfad,
        string $dateiName,
        int $index,
        int $fortschrittDenominator,
    ): string {
        $config = $this->parseConfig ?? $this->parseConfigResolver->resolve('bewerbungen');
        $markdownPfad = $cachePfad.'.parse-'.$config->engine->value.'-'.$config->llamaParseTier.'.md';
        $nutzeCache = ! $this->option('ohne-zwischenspeicher') && is_file($markdownPfad);
        $motor = $config->engine->label();

        if ($nutzeCache) {
            $this->statusZeile("  <fg=magenta>    ⊙</> [{$index}/{$fortschrittDenominator}] {$dateiName} – {$motor}-Text aus Zwischenspeicher");

            return file_get_contents($markdownPfad) ?: '';
        }

        $this->statusZeile("  <fg=blue>    </> [{$index}/{$fortschrittDenominator}] {$dateiName} – {$motor} liest die Datei …");
        $text = $this->aiGateway->parseAppDocument($lokalPfad, 'bewerbungen');
        $this->atomarSchreiben($markdownPfad, $text);

        return $text;
    }

    /**
     * @param  array<string, string>  $texte
     * @param  string[]  $anhangNamen
     * @return array<string, mixed>
     */
    private function auswertungMitKi(array $texte, array $anhangNamen, ?string $modell, AppSettings $appSettings): array
    {
        $kiProvider = $appSettings->bewerbungenAuswertungAiProvider;

        if ($kiProvider === BewerbungenAuswertungAiProvider::GemmaLlamaCpp
            && trim((string) config('ai.providers.gemma-llama-cpp.key', '')) === '') {
            throw new \Exception('Gemma ist gewählt, aber llama_cpp_api_key fehlt.');
        }

        if ($kiProvider === BewerbungenAuswertungAiProvider::Langdock
            && trim((string) config('services.langdock.api_key', '')) === '') {
            throw new \Exception('Langdock ist in den App-Einstellungen gewählt, aber LANGDOCK_API_KEY (services.langdock.api_key) fehlt.');
        }

        $prompt = $this->erstellePrompt($texte, $anhangNamen);

        $structured = $this->aiGateway->agent(
            BewerbungsAgent::make(),
            $prompt,
            new AiRequestContext(
                appIdentifier: 'bewerbungen',
                capability: AiCapability::Agent,
                providerOverride: $this->mapKiProvider($kiProvider),
                modelOverride: $modell,
            ),
        );

        if (! is_array($structured)) {
            throw new \Exception('KI-Antwort enthält keine strukturierten Daten.');
        }

        return $structured;
    }

    private function mapKiProvider(BewerbungenAuswertungAiProvider $provider): AiProvider
    {
        return match ($provider) {
            BewerbungenAuswertungAiProvider::GemmaLlamaCpp => AiProvider::GemmaLlamaCpp,
            BewerbungenAuswertungAiProvider::OpenWebUi => AiProvider::OpenWebUi,
            BewerbungenAuswertungAiProvider::Langdock => AiProvider::Langdock,
        };
    }

    /**
     * @param  array<string, string>  $texte
     * @param  string[]  $anhangNamen
     */
    private function dokumentenText(array $texte, array $anhangNamen): string
    {
        $grenze = max(1, (int) config('intranet-app-bewerbungen.ai.max_dokument_zeichen', 150_000));
        $abschnitte = [];
        $verwendet = 0;
        $gekuerzt = false;

        foreach ($texte as $dateiName => $text) {
            $istZeugnis = in_array($dateiName, $anhangNamen, true);
            $typ = $istZeugnis ? 'ZEUGNIS/ANHANG' : 'BEWERBUNGSDOKUMENT';
            $block = "=== {$typ}: {$dateiName} ===\n\n".trim($text);
            $naechsteLaenge = $verwendet + mb_strlen($block) + 2;

            if ($naechsteLaenge > $grenze) {
                $rest = $grenze - $verwendet;
                if ($rest > 500) {
                    $abschnitte[] = mb_substr($block, 0, $rest);
                    $verwendet += $rest;
                }
                $gekuerzt = true;

                break;
            }

            $abschnitte[] = $block;
            $verwendet = $naechsteLaenge;
        }

        $dokumentenText = implode("\n\n", $abschnitte);

        if ($gekuerzt) {
            $this->statusZeile('  <fg=yellow>    ⚠</> Unterlagen gekürzt: sie überschreiten '.$grenze.' Zeichen (Gemma-Kontextfenster).');
            $dokumentenText .= "\n\n[Hinweis: Weitere Unterlagen wurden gekürzt, weil sie das Kontextfenster des Modells überschreiten.]";
        }

        return $dokumentenText;
    }

    /**
     * @param  array<string, string>  $texte
     * @param  string[]  $anhangNamen
     */
    private function erstellePrompt(array $texte, array $anhangNamen): string
    {
        $dokumentenText = $this->dokumentenText($texte, $anhangNamen);

        $zeugnisHinweis = ! empty($anhangNamen)
            ? "\n\nDie folgenden Dokumente sind Zeugnisse/Anhänge: ".implode(', ', $anhangNamen)
            : "\n\nEs wurden keine Zeugnisdokumente beigefügt.";

        return <<<PROMPT
Analysiere die folgenden Bewerbungsunterlagen und extrahiere die angeforderten Informationen.

{$zeugnisHinweis}

--- BEGINN DER BEWERBUNGSUNTERLAGEN ---

{$dokumentenText}

--- ENDE DER BEWERBUNGSUNTERLAGEN ---

Hinweise zur Extraktion:
- "hoechster_schulabschluss": z.B. "Abitur", "Fachabitur", "Mittlere Reife", "Hauptschulabschluss"
- "durchschnittsnote": Notendurchschnitt aus dem letzten relevanten Zeugnis als Dezimalzahl
- "berufserfahrung_fachspezifisch": IT/Informatik-relevante Berufserfahrung, "keine" wenn nicht vorhanden
- "it_kenntnisse": Alle erwähnten IT-Kenntnisse, Programme, Programmiersprachen als Liste
- "letzte_schulform": z.B. "Berufsschule", "Gymnasium", "Gesamtschule", "Fachoberschule", "Berufskolleg"
- "luecken_im_lebenslauf": Zeiträume ohne Beschäftigung/Ausbildung (z.B. "01/2020 - 06/2020")
- "fehlstunden"/"fehlstunden_unentschuldigt": IMMER gezielt aus dem letzten Schulzeugnis extrahieren, falls ein Zeugnis vorhanden ist.
  * Suche nach Synonymen und typischen Bezeichnungen: "Fehlstunden", "Fehlzeiten", "versäumte Stunden", "Versäumnisse", "Unterrichtsversäumnisse", "davon unentschuldigt", "unentschuldigte Fehlstunden", "entschuldigt/unentschuldigt".
  * Extrahiere nur Ganzzahlen.
  * Wenn nur eine Gesamtzahl vorhanden ist: diese Zahl in "fehlstunden", und "fehlstunden_unentschuldigt" = null.
  * Wenn zwei Zahlen (gesamt + unentschuldigt) vorhanden sind: beide Felder setzen.
  * Wenn explizit 0 genannt wird: 0 übernehmen (nicht null).
  * Wenn im Zeugnis keine Angabe zu Fehlstunden existiert: null.
- "auffaelligkeiten": Nur diese Kategorien verwenden:
  * "Fehlende Zeugnisse" – wenn Zeugnisse fehlen oder unvollständig sind
  * "Abgebrochene Ausbildung: [Details]" – wenn eine Ausbildung abgebrochen wurde
  * "Abgebrochenes Studium: [Details]" – wenn ein Studium abgebrochen wurde
  * "Ausländische Zeugnisse" – wenn ausländische Schulzeugnisse vorhanden sind
  * "Deutschkenntnisse: [Sprachniveau/Details]" – wenn Deutsch nicht Muttersprache
  * "Besonders schlechte Noten: [Details]" – bei Noten schlechter als 4
  * "ITA-Ausbildung: Schulische Ausbildung zum Informationstechnischen Assistenten" – wenn der Bewerber eine schulische ITA-Ausbildung absolviert hat
- "verarbeitete_zeugnisse": Namen der Zeugnis-Dokumente, die du analysiert hast
PROMPT;
    }

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
        $sheet->setTitle('Bewerbungen (AI)');

        $spaltenBuchstaben = range('A', 'Z');
        $felder = array_keys(self::SPALTEN);
        $feldIndex = array_flip($felder);
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

            $this->setzeHyperlinkWennVorhanden(
                $sheet,
                $spaltenBuchstaben,
                $excelZeile,
                $feldIndex['bewerbung_ro'] ?? null,
                $ergebnis['bewerbung_ro'] ?? null,
            );
            $this->setzeHyperlinkWennVorhanden(
                $sheet,
                $spaltenBuchstaben,
                $excelZeile,
                $feldIndex['anhang_ro'] ?? null,
                $ergebnis['anhang_ro'] ?? null,
            );

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
            'verarbeitete_zeugnisse' => 40, 'bewerbung_ro' => 45,
            'anhang_ro' => 45, 'fehler' => 30,
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
     * @param  array<int, string>  $spaltenBuchstaben
     */
    private function setzeHyperlinkWennVorhanden(
        Worksheet $sheet,
        array $spaltenBuchstaben,
        int $excelZeile,
        ?int $colIndex,
        mixed $wert,
    ): void {
        if ($colIndex === null || ! is_string($wert) || $wert === '') {
            return;
        }

        $col = $spaltenBuchstaben[$colIndex] ?? null;
        if ($col === null) {
            return;
        }

        if (! str_starts_with($wert, 'http://') && ! str_starts_with($wert, 'https://')) {
            return;
        }

        $zelle = "{$col}{$excelZeile}";
        $sheet->getCell($zelle)->getHyperlink()->setUrl($wert);
        $sheet->getStyle($zelle)->getFont()->getColor()->setRGB('0563C1');
        $sheet->getStyle($zelle)->getFont()->setUnderline(true);
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
