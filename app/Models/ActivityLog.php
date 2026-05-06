<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'event_type',
        'subject_type',
        'subject_id',
        'message',
        'context',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public static function record(string $eventType, string $message, ?Model $subject = null, array $context = []): self
    {
        return self::create([
            'event_type' => $eventType,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'message' => $message,
            'context' => $context ?: null,
            'created_at' => now(),
        ]);
    }
}
