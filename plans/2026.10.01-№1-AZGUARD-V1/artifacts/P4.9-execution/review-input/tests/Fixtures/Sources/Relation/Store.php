<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Relation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Store extends Model
{
    public $timestamps = false;

    protected $table = 'relation_stores';

    protected $guarded = [];

    /** @return BelongsTo<Member, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'owner_id');
    }
}
