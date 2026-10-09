<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Models;

use Hwkdo\IntranetAppBewerbungen\Data\KiFeld;
use Hwkdo\IntranetAppBewerbungen\Database\Factories\KiDefinitionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class KiDefinition extends Model
{
    /** @use HasFactory<KiDefinitionFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'intranet_app_bewerbungen_ki_definitionen';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'felder' => 'array',
            'is_default' => 'boolean',
        ];
    }

    protected static function newFactory(): KiDefinitionFactory
    {
        return KiDefinitionFactory::new();
    }

    /**
     * @return list<KiFeld>
     */
    public function kiFelder(): array
    {
        $felder = $this->felder;

        if (! is_array($felder)) {
            return [];
        }

        return array_values(array_map(
            fn (mixed $feld): KiFeld => KiFeld::fromArray(is_array($feld) ? $feld : []),
            $felder,
        ));
    }

    public static function defaultDefinition(): ?self
    {
        return static::query()->where('is_default', true)->orderBy('id')->first()
            ?? static::query()->orderBy('id')->first();
    }

    public function alsStandardSetzen(): void
    {
        DB::transaction(function (): void {
            static::query()->where('is_default', true)->whereKeyNot($this->getKey())->update(['is_default' => false]);
            $this->is_default = true;
            $this->save();
        });
    }
}
