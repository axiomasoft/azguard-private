<?php

declare(strict_types=1);

namespace AzGuard\Testing;

use AzGuard\Testing\Internal\FakeSubjectBuilder;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;

/**
 * The smallest subject model: it has a key and no table, so a test of a panel, a source or a plugin needs no schema
 * for its users. Every key exists as a subject; nothing is read or written. It authenticates like any user model.
 */
#[Api(since: '1.0')]
final class FakeSubject extends Model implements AuthenticatableContract
{
    use Authenticatable;

    public const string TYPE = 'azguard.fake-subject';

    protected $table = 'azguard_fake_subjects';

    /** @var array<string> */
    protected $guarded = [];

    public static function of(int|string $id = 1): self
    {
        $subject = new self;
        $subject->setAttribute($subject->getKeyName(), $id);
        $subject->exists = true;

        return $subject;
    }

    public function getMorphClass(): string
    {
        return self::TYPE;
    }

    /**
     * @param  Builder  $query
     * @return FakeSubjectBuilder
     */
    #[Internal('Eloquent hook')]
    public function newEloquentBuilder($query): EloquentBuilder
    {
        return new FakeSubjectBuilder($query);
    }
}
