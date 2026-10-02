<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Panels;

use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\DuplicatePanelException;
use AzGuard\Exceptions\InvalidPanelIdException;
use AzGuard\Exceptions\RegistryFrozenException;
use AzGuard\Exceptions\UnknownPanelException;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * The panels of the application: registered and adjusted while the application boots, read-only afterwards.
 *
 * Panels are compiled once the application has booted; reading before that throws, so a half-built registry is
 * never observed.
 *
 * @api
 */
interface PanelRegistry
{
    /**
     * @throws UnknownPanelException
     * @throws DefinitionException when the panels are not compiled yet
     */
    public function get(string $id): Panel;

    /**
     * @throws DefinitionException when the panels are not compiled yet
     */
    public function find(string $id): ?Panel;

    /**
     * @return array<string, Panel> panels by id, in registration order
     *
     * @throws DefinitionException when the panels are not compiled yet
     */
    public function all(): array;

    /**
     * @param  class-string<Model>  $modelClass
     * @return list<Panel> panels that accept the model as a subject, in registration order
     *
     * @throws DefinitionException when the panels are not compiled yet
     */
    public function forModel(string $modelClass): array;

    /**
     * The panel declared as default for the model, otherwise the only panel of the model.
     *
     * @param  class-string<Model>  $modelClass
     *
     * @throws DefinitionException when the panels are not compiled yet
     */
    public function defaultFor(string $modelClass): ?Panel;

    /**
     * @param  class-string<PanelProvider>  $providerClass
     *
     * @throws DuplicatePanelException when the id is already registered, by the same class as well
     * @throws InvalidPanelIdException
     * @throws DefinitionException when the class is not a panel provider
     * @throws RegistryFrozenException
     */
    public function register(string $providerClass): void;

    /**
     * Replaces the provider registered under the same panel id.
     *
     * @param  class-string<PanelProvider>  $providerClass
     *
     * @throws UnknownPanelException when no panel has that id
     * @throws InvalidPanelIdException
     * @throws DefinitionException when the class is not a panel provider
     * @throws RegistryFrozenException
     */
    public function replace(string $providerClass): void;

    /**
     * Adds to one panel on behalf of its provider; an unknown id is reported when the panels are compiled.
     *
     * @param  Closure(PanelBuilder): mixed  $callback
     *
     * @throws RegistryFrozenException
     */
    public function configure(string $id, Closure $callback): void;

    /**
     * Applies one adjustment to every panel, below the panel's own provider in precedence.
     *
     * @param  Closure(PanelBuilder): mixed  $callback
     *
     * @throws RegistryFrozenException
     */
    public function configureAll(Closure $callback): void;

    public function isFrozen(): bool;
}
