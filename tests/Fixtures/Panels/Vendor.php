<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Panels;

use Illuminate\Database\Eloquent\Model;

/**
 * A subject model that names its own default panel.
 */
final class Vendor extends Model
{
    public ?string $defaultPanel = null;

    protected $table = 'vendors';

    public function azguardDefaultPanel(): ?string
    {
        return $this->defaultPanel;
    }
}
