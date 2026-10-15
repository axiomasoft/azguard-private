<?php

declare(strict_types=1);

require dirname(__DIR__, 4).'/vendor/autoload.php';

$source = <<<'SOURCE'
<?php

declare(strict_types=1);

namespace AuditGenerated;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum WidgetPermission: string
{
    case Sales = 'widgets.sales';
    case OtherSales = 'widgets.other-sales';
}

SOURCE;

// Distinct FQCNs with the same basename are valid Filament widgets after $azguardKey disambiguates keys.
$output = \AzGuard\Filament\Internal\Console\EnumMarks::add($source, [
    ['attribute' => \AzGuard\Filament\Attributes\ForFilament::class,
        'class' => \AzGuard\Tests\Fixtures\Filament\Widgets\Stats\SalesWidget::class],
    ['attribute' => \AzGuard\Filament\Attributes\ForFilament::class,
        'class' => \AzGuard\Tests\Fixtures\Filament\Widgets\Other\SalesWidget::class],
]);

file_put_contents(__DIR__.'/generated-widget-permission.php', $output);
echo "Generated evidence/filament/generated-widget-permission.php through EnumMarks::add().\n";
