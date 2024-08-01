<?php

namespace Kompo\Searchbar\Models;

use Kompo\Auth\Models\Model;

class SearchState extends Model
{
    protected $casts = [
        'type' => SearchStateType::class
    ];

    public function scopeGetAllForUser($query, $userId = null)
    {
        return $query->where(fn($q) => $q
            ->where('type', SearchStateType::USER)
            ->where('user_id', $userId ?? auth()->id())
        )->orWhere(fn($q) => $q
            ->where('type', SearchStateType::GLOBAL)
        );
    }
}