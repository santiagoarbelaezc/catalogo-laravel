<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $table = 'products';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'material',
        'category',
        'category_id',
        'subcategory_id',
        'options',
        'stock',
        'isNew',
        'isFeatured',
        'marca',
        'gramaje',
        'brandIconUrl',
    ];

    protected function casts(): array
    {
        return [
            'stock' => 'integer',
            'isNew' => 'boolean',
            'isFeatured' => 'boolean',
        ];
    }

    public function categoryRelation(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Subcategory::class, 'subcategory_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'product_id')
            ->orderByDesc('is_primary')
            ->orderBy('id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'product_id');
    }

    public function colors(): HasMany
    {
        return $this->hasMany(ProductColor::class, 'product_id');
    }
}

