<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Database\Factories;

use Hwkdo\IntranetAppBewerbungen\Models\KiDefinition;
use Hwkdo\IntranetAppBewerbungen\Support\StandardKiDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KiDefinition>
 */
class KiDefinitionFactory extends Factory
{
    protected $model = KiDefinition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'beschreibung' => fake()->sentence(),
            'instruktionen' => StandardKiDefinition::instruktionen(),
            'felder' => [
                [
                    'key' => 'nachname',
                    'label' => 'Nachname',
                    'typ' => 'text',
                    'hinweis' => 'Nachname des Bewerbers',
                    'pflicht' => true,
                    'optionen' => [],
                ],
            ],
            'is_default' => false,
        ];
    }

    public function standard(): static
    {
        return $this->state([
            'name' => StandardKiDefinition::NAME,
            'beschreibung' => 'Standardauswertung',
            'instruktionen' => StandardKiDefinition::instruktionen(),
            'felder' => StandardKiDefinition::felder(),
            'is_default' => true,
        ]);
    }
}
