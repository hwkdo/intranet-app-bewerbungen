<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Services;

use Hwkdo\IntranetAppBase\Contracts\DocumentParseConfigResolverInterface;
use Hwkdo\IntranetAppBase\Contracts\IntranetAiGatewayInterface;
use Hwkdo\IntranetAppBase\Data\AiRequestContext;
use Hwkdo\IntranetAppBase\Data\ResolvedDocumentParseConfig;
use Hwkdo\IntranetAppBase\Enums\AiCapability;
use Hwkdo\IntranetAppBase\Enums\AiProvider;
use Hwkdo\IntranetAppBewerbungen\Ai\Agents\DefinitionsAgent;
use Hwkdo\IntranetAppBewerbungen\Enums\BewerbungenAuswertungAiProvider;
use Hwkdo\IntranetAppBewerbungen\Models\IntranetAppBewerbungenSettings;
use Hwkdo\IntranetAppBewerbungen\Models\KiDefinition;
use Hwkdo\IntranetAppBewerbungen\Support\AnalyseLaufProtokoll;
use Hwkdo\IntranetAppBewerbungen\Support\ExtraktionsTextValidator;
use Hwkdo\IntranetAppBewerbungen\Support\KiErgebnisEnvelope;
use Hwkdo\MsGraphLaravel\Interfaces\MsGraphShareServiceInterface;
use Illuminate\Support\Facades\File;

class BewerbungAnalyseService
{
    /** @var string[] */
    private const ERLAUBTE_ENDUNGEN = [
        'pdf', 'doc', 'docx', 'txt', 'rtf', 'odt', 'ppt', 'pptx', 'xls', 'xlsx', 'csv',
        'html', 'htm', 'md', 'jpg', 'jpeg', 'png', 'tif', 'tiff', 'webp', 'bmp', 'heic', 'gif',
    ];

    private ?ResolvedDocumentParseConfig $parseConfig = null;

    public function __construct(
        private readonly MsGraphShareServiceInterface $shareService,
        private readonly IntranetAiGatewayInterface $aiGateway,
        private readonly DocumentParseConfigResolverInterface $parseConfigResolver,
    ) {}

    /**
     * @param  array{bewerbung_ro?: string|null, anhang_ro?: string|null}  $links
     * @param  (callable(string): void)|null  $status
     * @return array{definition: array{id: int|null, name: string, fallback: bool}, fields: list<array{key: string, label: string, typ: string, value: mixed}>, system: list<array{key: string, label: string, typ: string, value: mixed}>}
     */
    public function analysiereLinks(
        array $links,
        KiDefinition $definition,
        bool $fallback,
        ?string $modell = null,
        bool $ohneCache = false,
        ?callable $status = null,
        ?AnalyseLaufProtokoll $protokoll = null,
    ): array {
        $bewerbungRo = (string) ($links['bewerbung_ro'] ?? '');
        if ($bewerbungRo === '') {
            throw new \InvalidArgumentException('Kein Bewerbungslink vorhanden.');
        }

        $this->statusZeile($status, '  <fg=blue>→</> Schritt 1/4: Warte auf API – Bewerbungsordner (Graph) …');
        $bewerbungDateien = $this->messen($protokoll, 'graph', 'Lade Bewerbungsordner (Graph)', fn () => $this->shareService->getSharedFolderContents($bewerbungRo));
        $this->statusZeile($status, '  <fg=blue>  </> '.count($bewerbungDateien).' Datei(en) im Bewerbungsordner.');
        $protokoll?->schritt('graph', count($bewerbungDateien).' Datei(en) im Bewerbungsordner');

        $anhangDateien = [];
        $anhangRo = $links['anhang_ro'] ?? null;
        if (is_string($anhangRo) && $anhangRo !== '') {
            $this->statusZeile($status, '  <fg=blue>→</> Schritt 2/4: Warte auf API – Anhangsordner (Graph) …');
            try {
                $anhangDateien = $this->messen($protokoll, 'graph', 'Lade Anhangsordner (Graph)', fn () => $this->shareService->getSharedFolderContents($anhangRo));
                $this->statusZeile($status, '  <fg=blue>  </> '.count($anhangDateien).' Datei(en) im Anhangsordner.');
                $protokoll?->schritt('graph', count($anhangDateien).' Datei(en) im Anhangsordner');
            } catch (\Throwable) {
                $this->statusZeile($status, '  <fg=yellow>  ⚠</> Anhangsordner nicht abrufbar (optional, übersprungen).');
                $protokoll?->schritt('graph', 'Anhangsordner nicht abrufbar, übersprungen');
            }
        } else {
            $this->statusZeile($status, '  <fg=blue>→</> Schritt 2/4: Kein Anhangsordner – übersprungen.');
            $protokoll?->schritt('graph', 'Kein Anhangsordner');
        }

        $this->statusZeile($status, '  <fg=blue>→</> Schritt 3/4: Dateien laden / aus Zwischenspeicher lesen und Text extrahieren …');
        $protokoll?->schritt('extraktion', 'Dateien laden und Text extrahieren');
        $cacheId = hash('sha256', $bewerbungRo.'|'.(string) $anhangRo);
        $bewerbungTexte = $this->extrahiereDateiTexte($bewerbungDateien, $cacheId, 'bewerbung', $ohneCache, $status, $protokoll);
        $anhangTexte = $this->extrahiereDateiTexte($anhangDateien, $cacheId, 'anhang', $ohneCache, $status, $protokoll);
        $anhangNamen = array_keys($anhangTexte);
        $alleTexte = array_merge($bewerbungTexte, $anhangTexte);

        if ($alleTexte === []) {
            throw new \RuntimeException('Keine lesbaren Dateien. LlamaParse hat keinen verwendbaren Text geliefert.');
        }

        $this->statusZeile($status, '  <fg=blue>→</> Schritt 4/4: Warte auf KI-Antwort mit '.count($alleTexte).' Dokument(en) …');
        $roh = $this->messen(
            $protokoll,
            'llm',
            'Warte auf KI-Antwort ('.count($alleTexte).' Dokumente)',
            fn () => $this->auswertungMitKi($this->erstellePrompt($definition, $alleTexte, $anhangNamen, $status), $definition, $modell),
        );
        $this->statusZeile($status, '  <fg=green>  </> KI-Antwort erhalten.');
        $protokoll?->schritt('llm', 'KI-Antwort erhalten');

        $dokumente = array_keys($alleTexte);

        return KiErgebnisEnvelope::bauen($definition, $roh, [
            'bewerbung_ro' => $bewerbungRo,
            'anhang_ro' => is_string($anhangRo) ? $anhangRo : null,
        ], $dokumente, $fallback);
    }

    /**
     * @return array{definition: array{id: int|null, name: string, fallback: bool}, fields: list<array{key: string, label: string, typ: string, value: mixed}>, system: list<array{key: string, label: string, typ: string, value: mixed}>}
     */
    public function analysiereText(string $text, KiDefinition $definition, bool $fallback = false, ?string $modell = null): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('Kein Text zum Analysieren.');
        }

        $roh = $this->auswertungMitKi(
            $this->erstellePrompt($definition, ['eingefuegter-text.txt' => $text], []),
            $definition,
            $modell,
        );

        return KiErgebnisEnvelope::bauen($definition, $roh, [], ['eingefuegter-text.txt'], $fallback);
    }

    /**
     * @param  array<int, array<string, mixed>>  $dateiListe
     * @param  (callable(string): void)|null  $status
     * @return array<string, string>
     */
    private function extrahiereDateiTexte(array $dateiListe, string $cacheId, string $typ, bool $ohneCache, ?callable $status, ?AnalyseLaufProtokoll $protokoll = null): array
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

            $ext = strtolower(pathinfo((string) $dateiName, PATHINFO_EXTENSION));
            if (! in_array($ext, self::ERLAUBTE_ENDUNGEN, true)) {
                $this->statusZeile($status, "  <fg=yellow>  ⚠</> [{$index}/{$fortschrittDenominator}] Übersprungen (Format): {$dateiName}");

                continue;
            }

            $cachePfad = $this->cachePfadFuerDatei($cacheId, $typ, $dateiItem, $ext);
            $nutzeCache = ! $ohneCache && is_file($cachePfad);

            try {
                if ($nutzeCache) {
                    $this->statusZeile($status, "  <fg=magenta>    ⊙</> [{$index}/{$fortschrittDenominator}] {$dateiName} – aus Zwischenspeicher (kein Download)");
                    $protokoll?->schritt('extraktion', "{$dateiName} aus Zwischenspeicher ({$index}/{$fortschrittDenominator})");
                    $lokalPfad = $cachePfad;
                } else {
                    $this->statusZeile($status, "  <fg=blue>    ↓</> [{$index}/{$fortschrittDenominator}] {$dateiName} – Warte auf Download (Graph) …");
                    $inhalt = $this->messen(
                        $protokoll,
                        'graph',
                        "Lade {$dateiName} ({$index}/{$fortschrittDenominator})",
                        fn () => $this->shareService->downloadDriveItemContent($downloadUrl),
                    );
                    $this->atomarSchreiben($cachePfad, $inhalt);
                    $lokalPfad = $cachePfad;
                    $this->statusZeile($status, "  <fg=blue>    </> [{$index}/{$fortschrittDenominator}] {$dateiName} – gespeichert unter Zwischenspeicher");
                }

                $text = $this->messen(
                    $protokoll,
                    'extraktion',
                    "Extrahiere Text aus {$dateiName} ({$index}/{$fortschrittDenominator})",
                    fn () => $this->liesDokument($lokalPfad, $cachePfad, (string) $dateiName, $index, $fortschrittDenominator, $ohneCache, $status),
                );
                $bewertung = ExtraktionsTextValidator::bewerten($text);
                $this->statusZeile($status, $bewertung->istPlausibel
                    ? "  <fg=green>    ℹ</> Qualität: {$bewertung->beschreibung}"
                    : "  <fg=yellow>    ℹ</> Qualität: {$bewertung->beschreibung}");

                if ($bewertung->istPlausibel && trim($text) !== '') {
                    $this->statusZeile($status, '  <fg=green>    ✓</> '.$dateiName.' ('.strlen($text).' Zeichen, für KI verwendet)');
                    $texte[(string) $dateiName] = $text;
                } else {
                    $this->statusZeile($status, "  <fg=yellow>    ⚠</> {$dateiName} – nicht für KI verwendet (Extraktion unplausibel oder leer)");
                }
            } catch (\Throwable $e) {
                $this->statusZeile($status, "  <fg=red>    ✗</> {$dateiName}: ".$e->getMessage());
            }
        }

        return $texte;
    }

    /**
     * @param  array<string, mixed>  $dateiItem
     */
    private function cachePfadFuerDatei(string $cacheId, string $typ, array $dateiItem, string $ext): string
    {
        $eTag = $dateiItem['eTag'] ?? $dateiItem['@odata.etag'] ?? '';
        $signatur = hash('sha256', implode('|', [
            $cacheId,
            $typ,
            (string) ($dateiItem['id'] ?? ''),
            (string) ($dateiItem['name'] ?? ''),
            (string) $eTag,
            (string) ($dateiItem['size'] ?? ''),
        ]));
        $sichereExt = preg_match('/^[a-z0-9]{1,8}$/', $ext) ? $ext : 'bin';

        return $this->downloadCacheVerzeichnis().'/'.$signatur.'.'.$sichereExt;
    }

    private function downloadCacheVerzeichnis(): string
    {
        $relativ = (string) config('intranet-app-bewerbungen.ai.download_cache_path', 'bewerbungen_auswertung_cache');
        $pfad = storage_path('app/'.$relativ);
        File::ensureDirectoryExists($pfad);

        return $pfad;
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

    /**
     * @param  (callable(string): void)|null  $status
     */
    private function liesDokument(
        string $lokalPfad,
        string $cachePfad,
        string $dateiName,
        int $index,
        int $fortschrittDenominator,
        bool $ohneCache,
        ?callable $status,
    ): string {
        $config = $this->parseConfig ??= $this->parseConfigResolver->resolve('bewerbungen');
        $markdownPfad = $cachePfad.'.parse-'.$config->engine->value.'-'.$config->llamaParseTier.'.md';
        $motor = $config->engine->label();

        if (! $ohneCache && is_file($markdownPfad)) {
            $this->statusZeile($status, "  <fg=magenta>    ⊙</> [{$index}/{$fortschrittDenominator}] {$dateiName} – {$motor}-Text aus Zwischenspeicher");

            return file_get_contents($markdownPfad) ?: '';
        }

        $this->statusZeile($status, "  <fg=blue>    </> [{$index}/{$fortschrittDenominator}] {$dateiName} – {$motor} liest die Datei …");
        $text = $this->aiGateway->parseAppDocument($lokalPfad, 'bewerbungen');
        $this->atomarSchreiben($markdownPfad, $text);

        return $text;
    }

    /**
     * @param  array<string, string>  $texte
     * @param  string[]  $anhangNamen
     * @param  (callable(string): void)|null  $status
     */
    private function erstellePrompt(KiDefinition $definition, array $texte, array $anhangNamen, ?callable $status = null): string
    {
        $dokumentenText = $this->dokumentenText($texte, $anhangNamen, $status);
        $zeugnisHinweis = $anhangNamen !== []
            ? "\n\nDie folgenden Dokumente sind Zeugnisse/Anhänge: ".implode(', ', $anhangNamen)
            : "\n\nEs wurden keine Zeugnisdokumente beigefügt.";
        $hinweise = collect($definition->kiFelder())
            ->map(fn ($feld): string => '- "'.$feld->key.'": '.($feld->hinweis !== '' ? $feld->hinweis : $feld->label))
            ->implode("\n");

        return <<<PROMPT
Analysiere die folgenden Bewerbungsunterlagen und extrahiere die angeforderten Informationen.
{$zeugnisHinweis}

--- BEGINN DER BEWERBUNGSUNTERLAGEN ---

{$dokumentenText}

--- ENDE DER BEWERBUNGSUNTERLAGEN ---

Hinweise zur Extraktion:
{$hinweise}
PROMPT;
    }

    /**
     * @param  array<string, string>  $texte
     * @param  string[]  $anhangNamen
     * @param  (callable(string): void)|null  $status
     */
    private function dokumentenText(array $texte, array $anhangNamen, ?callable $status): string
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
            $this->statusZeile($status, '  <fg=yellow>    ⚠</> Unterlagen gekürzt: sie überschreiten '.$grenze.' Zeichen (Gemma-Kontextfenster).');
            $dokumentenText .= "\n\n[Hinweis: Weitere Unterlagen wurden gekürzt, weil sie das Kontextfenster des Modells überschreiten.]";
        }

        return $dokumentenText;
    }

    /**
     * @return array<string, mixed>
     */
    private function auswertungMitKi(string $prompt, KiDefinition $definition, ?string $modell): array
    {
        $appSettings = IntranetAppBewerbungenSettings::resolvedAppSettings();
        $kiProvider = $appSettings->bewerbungenAuswertungAiProvider;
        $this->pruefeProvider($kiProvider);

        $structured = $this->aiGateway->agent(
            new DefinitionsAgent($definition),
            $prompt,
            new AiRequestContext(
                appIdentifier: 'bewerbungen',
                capability: AiCapability::Agent,
                providerOverride: $this->mapKiProvider($kiProvider),
                modelOverride: $modell,
            ),
        );

        if (! is_array($structured)) {
            throw new \RuntimeException('KI-Antwort enthält keine strukturierten Daten.');
        }

        return $structured;
    }

    private function pruefeProvider(BewerbungenAuswertungAiProvider $kiProvider): void
    {
        if ($kiProvider === BewerbungenAuswertungAiProvider::GemmaLlamaCpp
            && trim((string) config('ai.providers.gemma-llama-cpp.key', '')) === '') {
            throw new \RuntimeException('Gemma ist gewählt, aber llama_cpp_api_key fehlt.');
        }

        if ($kiProvider === BewerbungenAuswertungAiProvider::Langdock
            && trim((string) config('services.langdock.api_key', '')) === '') {
            throw new \RuntimeException('Langdock ist in den App-Einstellungen gewählt, aber LANGDOCK_API_KEY (services.langdock.api_key) fehlt.');
        }
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
     * @param  (callable(string): void)|null  $status
     */
    private function statusZeile(?callable $status, string $nachricht): void
    {
        if ($status !== null) {
            $status($nachricht);
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function messen(?AnalyseLaufProtokoll $protokoll, string $phase, string $text, callable $callback): mixed
    {
        $protokoll?->schritt($phase, $text);
        $start = microtime(true);

        try {
            return $callback();
        } finally {
            $protokoll?->addMs($phase, (int) round((microtime(true) - $start) * 1000));
        }
    }
}
