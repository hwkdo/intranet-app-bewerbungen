@props([
    'name',
    'text',
    'breite' => 0,
    'farbe' => 'bg-sky-500',
])

<div>
    <div class="flex justify-between gap-3 text-sm">
        <span>{{ $name }}</span>
        <span class="text-zinc-500">{{ $text }}</span>
    </div>
    <div class="mt-1 h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
        <div @class(['h-full', $farbe]) style="width: {{ max(0, min(100, (float) $breite)) }}%"></div>
    </div>
</div>
