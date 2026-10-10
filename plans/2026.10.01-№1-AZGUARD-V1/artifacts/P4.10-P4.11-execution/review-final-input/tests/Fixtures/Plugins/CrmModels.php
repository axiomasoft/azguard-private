<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Plugins;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Models of the CRM plugin by name; a class that does not fit is rejected before any panel is built.
 */
final readonly class CrmModels
{
    /**
     * @param  class-string<Model&Authenticatable>  $subject
     * @param  class-string<Model>  $organization
     * @param  class-string<Model>  $project
     * @param  class-string<Model>  $client
     */
    public function __construct(
        public string $subject,
        public string $organization,
        public string $project,
        public string $client,
    ) {
        foreach ([$subject, $organization, $project, $client] as $class) {
            if (! is_a($class, Model::class, true)) {
                throw new InvalidArgumentException('Expected Model: '.$class);
            }
        }

        if (! is_a($subject, Authenticatable::class, true)) {
            throw new InvalidArgumentException('subject must implement Authenticatable');
        }
    }
}
