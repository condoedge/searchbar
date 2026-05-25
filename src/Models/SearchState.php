<?php

namespace Kompo\Searchbar\Models;

use Condoedge\Utils\Models\Model;
use Kompo\Auth\Contracts\Security\NoTeamScope;

class SearchState extends Model implements NoTeamScope
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