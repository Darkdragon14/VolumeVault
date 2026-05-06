<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DockerVolume extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'driver',
        'mountpoint',
        'labels',
        'options',
        'exists',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'labels' => 'array',
            'options' => 'array',
            'exists' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }
}
