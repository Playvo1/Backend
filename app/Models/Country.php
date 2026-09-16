<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    /**
     * The cities that belong to this country.
     */
    public function cities(): HasMany
    {
        return $this->hasMany(City::class, 'country_id');
    }
}
