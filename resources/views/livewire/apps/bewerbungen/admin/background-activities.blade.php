<?php

use function Livewire\Volt\title;

title('Hintergrund');

?>

<div>
    <x-intranet-app-bewerbungen::bewerbungen-layout heading="Hintergrund" subheading="Geplante Tasks, Jobs, Mails und Benachrichtigungen">
        @livewire('intranet-app-base::background-activities', ['appIdentifier' => 'bewerbungen'])
    </x-intranet-app-bewerbungen::bewerbungen-layout>
</div>
