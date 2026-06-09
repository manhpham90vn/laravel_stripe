<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    protected $guarded = [];

    protected $casts = [
        'outcomes' => 'array',
    ];

    /** Cover gradient palettes — keyed deterministically off the id so the UI is stable. */
    private const COVERS = [
        ['from-rose-400', 'to-orange-300'],
        ['from-brand-500', 'to-sky-400'],
        ['from-emerald-400', 'to-teal-300'],
        ['from-violet-500', 'to-fuchsia-400'],
        ['from-amber-400', 'to-rose-300'],
    ];

    public function batches(): HasMany
    {
        return $this->hasMany(SaleBatch::class);
    }

    /** Featured batch shown on cards: the one on sale, else the most recent. */
    protected function batch(): Attribute
    {
        return Attribute::get(fn () => $this->batches->firstWhere('status', 'on_sale') ?? $this->batches->last());
    }

    // --- Presentation aliases used by the Blade views ---------------------

    protected function excerpt(): Attribute
    {
        return Attribute::get(fn () => $this->summary);
    }

    protected function lessons(): Attribute
    {
        return Attribute::get(fn () => $this->lessons_count);
    }

    protected function duration(): Attribute
    {
        return Attribute::get(fn () => $this->duration_label);
    }

    protected function coverFrom(): Attribute
    {
        return Attribute::get(fn () => self::COVERS[$this->id % count(self::COVERS)][0]);
    }

    protected function coverTo(): Attribute
    {
        return Attribute::get(fn () => self::COVERS[$this->id % count(self::COVERS)][1]);
    }
}
