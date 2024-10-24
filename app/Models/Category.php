<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Category extends Model
{
    protected $fillable = ['parent_id', 'name', 'slug', 'full_slug', 'level', 'external_id'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public static function boot(): void
    {
        parent::boot();

        static::saving(function ($model) {
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->name);
            }

            if ($model->parent_id) {
                $parent = Category::find($model->parent_id);
                $model->level = $parent->level + 1;
            } else {
                $model->level = 1;
            }

            $model->full_slug = $model->generateFullSlug();
        });
    }

    public function generateFullSlug()
    {
        if ($this->parent_id) {
            $parent = Category::find($this->parent_id);
            return $parent->full_slug . '/' . $this->slug;
        } else {
            return $this->slug;
        }
    }

}
