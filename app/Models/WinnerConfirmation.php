<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WinnerConfirmation extends Model
{
    protected $guarded = [];
    protected $casts = ['offered_value' => 'decimal:2', 'response_deadline' => 'datetime', 'notified_at' => 'datetime', 'responded_at' => 'datetime'];
}
