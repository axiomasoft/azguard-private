<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidAssignmentScopeException;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\SubjectRef;

it('keeps an assignment scope key unambiguous and composite cache keys apart', function (): void {
    expect(fn () => AssignmentScopeRef::of('workspace:a', 7))->toThrow(InvalidAssignmentScopeException::class)
        ->and(AssignmentScopeRef::of('w', 7)->key())->toBe(AssignmentScopeRef::of('w', '7')->key())
        ->and(AssignmentScopeRef::of('workspace', 'a:7')->key())
        ->not->toBe(AssignmentScopeRef::of('workspace', 'a')->key());

    $subject = SubjectRef::of('user', 1);

    expect(IdentityCodec::digest(['admin', $subject, [AssignmentScopeRef::of('workspace', 'a:7')]]))
        ->not->toBe(IdentityCodec::digest(['admin', $subject, [AssignmentScopeRef::of('workspace', 'a')], '7']));
});
