<?php

use function Livewire\Volt\{title};

title('Bewerbungen - App-Info');

?>

<x-intranet-app-bewerbungen::bewerbungen-layout heading="App-Info" subheading="Installierte Version und Release-Historie">
    @livewire('intranet-app-base::app-info', ['appIdentifier' => 'bewerbungen'])
</x-intranet-app-bewerbungen::bewerbungen-layout>
