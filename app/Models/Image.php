<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Image extends Model
{
    protected $fillable = ['product_id', 'url'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
