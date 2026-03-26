<?php

use function Livewire\Volt\{title};

title('Bewerbungen - Meine Einstellungen');

?>

<x-intranet-app-bewerbungen::bewerbungen-layout heading="Meine Einstellungen" subheading="Persönliche Einstellungen für die Bewerbungen App">
    @livewire('intranet-app-base::user-settings', ['appIdentifier' => 'bewerbungen'])
</x-intranet-app-bewerbungen::bewerbungen-layout>
