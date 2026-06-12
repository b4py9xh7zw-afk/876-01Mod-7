<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScoreAppealLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'appeal_id',
        'handler_id',
        'action',
        'score_adjustment',
        'opinion',
        'from_status',
        'to_status',
    ];

    protected $casts = [
        'appeal_id' => 'integer',
        'handler_id' => 'integer',
        'score_adjustment' => 'decimal:2',
    ];

    public function appeal()
    {
        return $this->belongsTo(ScoreAppeal::class, 'appeal_id');
    }

    public function handler()
    {
        return $this->belongsTo(User::class, 'handler_id');
    }
}
