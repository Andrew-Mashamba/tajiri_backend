<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentGraphEdge extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'source_type', 'source_id', 'target_type', 'target_id',
        'edge_type', 'weight', 'created_at',
    ];

    protected $casts = [
        'weight' => 'float',
        'created_at' => 'datetime',
    ];

    public const EDGE_SHARED = 'SHARED';
    public const EDGE_REPLIED_TO = 'REPLIED_TO';
    public const EDGE_STITCHED = 'STITCHED';
    public const EDGE_MENTIONED_CREATOR = 'MENTIONED_CREATOR';
    public const EDGE_HASHTAG_CO_OCCURRENCE = 'HASHTAG_CO_OCCURRENCE';
    public const EDGE_SAME_THREAD = 'SAME_THREAD';
    public const EDGE_CREATOR_OF = 'CREATOR_OF';
    public const EDGE_FOLLOWED_THEN_CREATED = 'FOLLOWED_THEN_CREATED';
}
