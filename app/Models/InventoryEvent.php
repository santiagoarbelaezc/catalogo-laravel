<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryEvent extends Model
{
    protected $table = 'inventario_eventos';

    public $timestamps = false;

    protected $fillable = [
        'producto_id',
        'stock_nuevo',
        'origen',
        'creado_at',
    ];

    protected function casts(): array
    {
        return [
            'producto_id' => 'integer',
            'stock_nuevo' => 'integer',
        ];
    }
}

