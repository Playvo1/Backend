<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Image extends Model
{
    /**
     * The model this image belongs to (imageable_type / imageable_id).
     */
    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }
}
