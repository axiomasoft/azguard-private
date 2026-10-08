<?php

declare(strict_types=1);

namespace AzGuard\Testing;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;

/**
 * The smallest subject model: it has a key and no table, so a test of a panel, a source or a plugin needs no schema
 * for its users. Every key exists as a subject; nothing is read or written. It authenticates like any user model.
 *
 * @api
 */
final class FakeSubject extends Model implements AuthenticatableContract
{
    use Authenticatable;

    public const string TYPE = 'azguard.fake-subject';

    protected $table = 'azguard_fake_subjects';

    /** @var list<string> */
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
     */
    public function newEloquentBuilder($query): FakeSubjectBuilder
    {
        return new FakeSubjectBuilder($query);
    }
}
