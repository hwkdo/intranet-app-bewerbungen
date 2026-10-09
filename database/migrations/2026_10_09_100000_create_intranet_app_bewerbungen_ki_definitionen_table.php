<?php

declare(strict_types=1);

use Hwkdo\IntranetAppBewerbungen\Support\StandardKiDefinition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intranet_app_bewerbungen_ki_definitionen', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('beschreibung')->nullable();
            $table->longText('instruktionen');
            $table->json('felder');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique('name', 'iab_kidef_name_uq');
            $table->index('is_default', 'iab_kidef_default_idx');
        });

        DB::table('intranet_app_bewerbungen_ki_definitionen')->insert([
            'name' => StandardKiDefinition::NAME,
            'beschreibung' => 'Standardauswertung für Bewerbungen, ausgerichtet auf IT-Ausbildungsstellen. Enthält den aktuellen Wohnort.',
            'instruktionen' => StandardKiDefinition::instruktionen(),
            'felder' => json_encode(StandardKiDefinition::felder(), JSON_THROW_ON_ERROR),
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('intranet_app_bewerbungen_ki_definitionen');
    }
};
