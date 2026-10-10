<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Explanation;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;

it('copies a plain readonly diagnostic snapshot and redacts secret names case insensitively', function (): void {
    $state = CodeStateToken::of('admin', 'build', 'fingerprint');
    $decision = Decision::deny(DecisionReason::Restricted, $state, AccessScope::in(TenantRef::global()), message: 'token-value');
    $field = 'token-value';
    $steps = [['stage' => 'contribution', 'component' => 'test', 'outcome' => 'contribution', 'detail' => ['API_TOKEN' => &$field, 'nested' => ['Credential' => ['secret-value']], 'safe' => 'kept', 'object' => new stdClass]]];
    $explanation = new Explanation($decision, $steps, new DateTimeImmutable('2026-10-05T12:00:00Z'), SubjectRef::of('user', '01'));
    $field = 'changed';
    $steps[0]['stage'] = 'mutated';
    $array = $explanation->toArray();
    expect($array['steps'][0]['stage'])->toBe('contribution')->and($array['decision']['message'])->toBe('[redacted]')
        ->and($array['steps'][0]['detail'])->toBe(['API_TOKEN' => '[redacted]', 'nested' => ['Credential' => '[redacted]'], 'safe' => 'kept', 'object' => '[redacted]'])
        ->and($explanation->decision())->toBe($decision);
    $array['steps'][0]['detail']['safe'] = 'changed';
    expect($explanation->steps()[0]['detail']['safe'])->toBe('kept');
});
