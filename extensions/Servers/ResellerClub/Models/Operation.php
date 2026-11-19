<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Models;

use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Operation extends Model
{
    protected $table = 'ext_resellerclub_operations';

    protected $guarded = [];

    protected $casts = ['payload' => 'array'];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
