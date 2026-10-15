<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Background;

use Hwkdo\IntranetAppBase\Data\BackgroundActivityDefinition;
use Hwkdo\IntranetAppBewerbungen\IntranetAppBewerbungen;
use Hwkdo\IntranetAppBewerbungen\Jobs\AnalyzeLegacyBewerbungJob;

final class BewerbungenBackgroundActivities
{
    /**
     * @return list<BackgroundActivityDefinition>
     */
    public static function definitions(): array
    {
        return [
            BackgroundActivityDefinition::queued(
                key: 'bewerbungen.analyze-legacy',
                label: 'Bewerbung analysieren',
                appIdentifier: IntranetAppBewerbungen::identifier(),
                appName: IntranetAppBewerbungen::app_name(),
                job: AnalyzeLegacyBewerbungJob::class,
                description: 'KI-Analyse eingehender Bewerbungen.',
            ),
        ];
    }
}
