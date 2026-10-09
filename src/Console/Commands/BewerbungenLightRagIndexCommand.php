<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Console\Commands;

use Hwkdo\IntranetAppBase\Contracts\IntranetAiGatewayInterface;
use Hwkdo\IntranetAppBewerbungen\Services\LegacyBewerbungenStelleClient;
use Hwkdo\IntranetAppBewerbungen\Services\LightRagBewerbungenClient;
use Hwkdo\IntranetAppBewerbungen\Support\BewerbungLightRagFiles;
use Hwkdo\IntranetAppBewerbungen\Support\BewerbungLightRagPayload;
use Hwkdo\MsGraphLaravel\Interfaces\MsGraphShareServiceInterface;
use Illuminate\Console\Command;
use Throwable;

class BewerbungenLightRagIndexCommand extends Command
{
    protected $signature = 'bewerbungen:lightrag-index
                            {stelle : ID der Stelle im Legacy-Intranet}
                            {instanz : Ziel-Instanz: perso-azubi-fisi, perso-ae oder perso-fisi}';

    protected $description = 'Lädt alle Bewerbungen einer Stelle testweise nach LightRAG';

    public function handle(
        LegacyBewerbungenStelleClient $legacy,
        LightRagBewerbungenClient $lightRag,
        MsGraphShareServiceInterface $shares,
        IntranetAiGatewayInterface $gateway,
    ): int {
        if (app()->runningUnitTests() && ! config('intranet-app-bewerbungen.lightrag.execute_in_tests')) {
            $this->comment('LightRAG-Testlauf ist in Tests ausgeschaltet.');

            return self::SUCCESS;
        }

        $stelleId = (int) $this->argument('stelle');
        $instanz = (string) $this->argument('instanz');
        $bekannt = array_keys(config('intranet-app-bewerbungen.lightrag.instances', []));

        if (! in_array($instanz, $bekannt, true) || $lightRag->baseUrl($instanz) === '') {
            $this->error('Unbekannte Instanz „'.$instanz.'“. Erlaubt: '.implode(', ', $bekannt));

            return self::FAILURE;
        }

        try {
            $payload = $legacy->fetch($stelleId);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $stelle = $payload['stelle'];
        $bewerbungen = $payload['bewerbungen'];

        $this->info('Stelle '.($stelle['bezeichnung'] ?? $stelleId).' → '.$instanz.' ('.$lightRag->baseUrl($instanz).'): '.count($bewerbungen).' Bewerbung(en).');

        $fehler = 0;

        foreach ($bewerbungen as $bewerbung) {
            $bewerbungId = (int) ($bewerbung['id'] ?? 0);
            if ($bewerbungId <= 0) {
                $this->error('Bewerbung ohne ID übersprungen.');
                $fehler++;

                continue;
            }

            try {
                $this->indexBewerbung($instanz, $bewerbungId, $stelle, $bewerbung, $lightRag, $shares, $gateway);
            } catch (Throwable $exception) {
                $fehler++;
                $this->error('Bewerbung '.$bewerbungId.': '.$exception->getMessage());
            }
        }

        if ($fehler > 0) {
            $this->error($fehler.' Bewerbung(en) fehlgeschlagen.');

            return self::FAILURE;
        }

        $this->info('Fertig. Die Verarbeitung in LightRAG läuft danach von allein weiter.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $stelle
     * @param  array<string, mixed>  $bewerbung
     */
    private function indexBewerbung(
        string $instanz,
        int $bewerbungId,
        array $stelle,
        array $bewerbung,
        LightRagBewerbungenClient $lightRag,
        MsGraphShareServiceInterface $shares,
        IntranetAiGatewayInterface $gateway,
    ): void {
        $dateien = array_merge(
            $this->dateien($shares, $bewerbung['cloud_bewerbung_ro'] ?? null, 'bewerbung'),
            $this->dateien($shares, $bewerbung['cloud_anhang_ro'] ?? null, 'anhang'),
        );

        $hochgeladen = [];
        $ausgelassen = [];
        $inhalte = [];
        $ocrDateien = [];

        foreach ($dateien as $datei) {
            if (BewerbungLightRagFiles::needsOcr($datei['name'])) {
                $hochgeladen[] = $datei['name'].' (OCR)';
                $ocrDateien[] = $datei;
            } elseif (BewerbungLightRagFiles::isDirect($datei['name'])) {
                $hochgeladen[] = $datei['name'];
                $inhalte[] = $datei;
            } else {
                $ausgelassen[] = $datei['name'];
            }
        }

        $text = BewerbungLightRagPayload::text($stelle, $bewerbung, $hochgeladen, $ausgelassen);
        $textResult = $lightRag->insertText($instanz, $text, BewerbungLightRagPayload::fileSource($bewerbungId));
        $this->line('Bewerbung '.$bewerbungId.' Stammdaten: '.($textResult['already_present'] ? 'bereits vorhanden' : $textResult['track_id']));

        foreach ($ausgelassen as $name) {
            $this->warn('  ausgelassen ('.$name.')');
        }

        foreach ($inhalte as $datei) {
            $uploadName = BewerbungLightRagFiles::uploadName($bewerbungId, $datei['ordner'], $datei['name']);
            $inhalt = $shares->downloadDriveItemContent($datei['download_url']);
            $upload = $lightRag->uploadContents($instanz, $inhalt, $uploadName);
            $this->line('  '.$datei['name'].': '.($upload['already_present'] ? 'bereits vorhanden' : $upload['track_id']));
        }

        foreach ($ocrDateien as $datei) {
            try {
                $inhalt = $shares->downloadDriveItemContent($datei['download_url']);
                $markdown = $this->parseContents($gateway, $inhalt, $datei['name']);
                $fileSource = BewerbungLightRagFiles::markdownName($bewerbungId, $datei['ordner'], $datei['name']);
                $textResult = $lightRag->insertText($instanz, '# '.$datei['name']."\n\n".$markdown, $fileSource);
                $this->line('  '.$datei['name'].' OCR: '.($textResult['already_present'] ? 'bereits vorhanden' : $textResult['track_id']));
            } catch (Throwable $exception) {
                $this->warn('  '.$datei['name'].' OCR fehlgeschlagen: '.$exception->getMessage());
            }
        }
    }

    private function parseContents(IntranetAiGatewayInterface $gateway, string $contents, string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $extension = preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1 ? $extension : 'bin';
        $path = tempnam(sys_get_temp_dir(), 'bewerbung-rag-');
        if ($path === false) {
            throw new \RuntimeException('Temporäre Datei konnte nicht angelegt werden.');
        }

        $filePath = $path.'.'.$extension;
        rename($path, $filePath);

        try {
            file_put_contents($filePath, $contents);

            return $gateway->parseAppDocument($filePath, 'bewerbungen');
        } finally {
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        }
    }

    /**
     * @return list<array{ordner: string, name: string, download_url: string}>
     */
    private function dateien(MsGraphShareServiceInterface $shares, mixed $shareUrl, string $ordner): array
    {
        if (! is_string($shareUrl) || trim($shareUrl) === '') {
            return [];
        }

        try {
            $items = $shares->getSharedFolderContents($shareUrl);
        } catch (Throwable $exception) {
            $this->warn('Ordner '.$ordner.' nicht lesbar: '.$exception->getMessage());

            return [];
        }

        $dateien = [];
        foreach ($items as $item) {
            if (! is_array($item) || isset($item['folder'])) {
                continue;
            }

            $name = $item['name'] ?? null;
            $downloadUrl = $item['@microsoft.graph.downloadUrl'] ?? null;
            if (! is_string($name) || $name === '' || ! is_string($downloadUrl) || $downloadUrl === '') {
                continue;
            }

            $dateien[] = [
                'ordner' => $ordner,
                'name' => $name,
                'download_url' => $downloadUrl,
            ];
        }

        return $dateien;
    }
}
