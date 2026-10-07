<?php

declare(strict_types=1);

use AzGuard\Exceptions\DefinitionException;
use AzGuard\Tests\Fixtures\Scopes\ActiveProjects;
use AzGuard\Tests\Fixtures\Scopes\ConfiguredProjectScope;
use AzGuard\Tests\Fixtures\Scopes\Project;
use AzGuard\Tests\Fixtures\Scopes\ProjectDirectory;

it('clones filter, label and directory settings and rejects a filter that is not the contract', function (): void {
    $scope = ConfiguredProjectScope::make();
    $byObject = $scope->filter(new ActiveProjects);
    $byClass = $scope->filter(ActiveProjects::class);
    $presented = $scope->label('Projects')->directory(ProjectDirectory::class);

    expect($scope->settings()->filters)->toBe([])
        ->and($scope->settings()->label)->toBeNull()
        ->and($scope->settings()->directory)->toBeNull()
        ->and($byObject)->not->toBe($scope)
        ->and($byObject->settings()->filters)->toHaveCount(1)
        ->and($byObject->settings()->filters[0])->toBeInstanceOf(ActiveProjects::class)
        ->and($byClass->settings()->filters)->toBe([ActiveProjects::class])
        ->and($presented->settings()->label)->toBe('Projects')
        ->and($presented->settings()->directory)->toBe(ProjectDirectory::class)
        ->and($scope->model())->toBe(Project::class)
        ->and(fn () => $scope->filter('seller-city'))->toThrow(DefinitionException::class, 'seller-city')
        ->and(fn () => $scope->filter(stdClass::class))->toThrow(DefinitionException::class, stdClass::class)
        ->and(fn () => $scope->directory(stdClass::class))->toThrow(DefinitionException::class, stdClass::class);
});
