<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A photo attached to another entity (ERD IMAGES), e.g. a venue's gallery.
 */
class Image extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'image_url',
        'sort_order',
    ];

    /**
     * The model this image belongs to (imageable_type / imageable_id).
     */
    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }
}
