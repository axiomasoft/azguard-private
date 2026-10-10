<?php

declare(strict_types=1);

namespace AzGuard\Sources\Folder;

use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Permissions\Describe;
use AzGuard\Permissions\GrantedToAll;
use AzGuard\Permissions\PolicyOnly;
use AzGuard\Permissions\RequiresGrant;
use AzGuard\Permissions\Resource;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use ReflectionAttribute;
use ReflectionClassConstant;
use ReflectionEnum;

/**
 * Turns the attributes of a string-backed permission enum into catalog rows.
 *
 * A case attribute replaces the enum attribute. Both authority attributes on one element, or none
 * that applies, is a definition error. {@see GrantedToAll} is recorded and does not grant anything here.
 */
final class AttributeReader
{
    /**
     * @param  class-string<BackedEnum>  $enum
     * @param  string|null  $group  relative group path, used when the case and the resource name no group
     * @return list<array{local: string, authority: string, label: ?string, group: ?string, description: ?string, case: array{enum: string, name: string}, resource_model: ?string, granted_to_all: bool}>
     *
     * @throws DefinitionException
     */
    public static function rows(string $enum, ?string $group): array
    {
        $reflection = new ReflectionEnum($enum);
        $resource = self::resource($reflection, $enum);
        $rows = [];

        foreach ($reflection->getCases() as $case) {
            $value = $case->getBackingValue();

            if (! is_string($value)) {
                throw new DefinitionException($enum.'::'.$case->getName().' is not a string-backed case.');
            }

            $describe = self::described($case);
            $rows[] = [
                'local' => $value,
                'authority' => self::authority($reflection, $case, $enum)->value,
                'label' => $describe instanceof Describe ? $describe->label : null,
                'group' => ($describe instanceof Describe ? $describe->group : null) ?? $resource['label'] ?? self::blank($group),
                'description' => $describe instanceof Describe ? $describe->description : null,
                'case' => ['enum' => $enum, 'name' => $case->getName()],
                'resource_model' => $resource['model'],
                'granted_to_all' => $case->getAttributes(GrantedToAll::class) !== [],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     *
     * @throws DefinitionException
     */
    public static function definition(array $row): PermissionDefinition
    {
        $local = $row['local'] ?? null;
        $authority = $row['authority'] ?? null;
        $label = $row['label'] ?? null;
        $group = $row['group'] ?? null;
        $description = $row['description'] ?? null;
        $case = $row['case'] ?? null;
        $model = $row['resource_model'] ?? null;

        if (! is_string($local) || ! is_string($authority) || ($label !== null && ! is_string($label))
            || ($group !== null && ! is_string($group)) || ($description !== null && ! is_string($description))
            || ($model !== null && ! is_string($model))) {
            throw new DefinitionException('A cached permission definition is not a catalog row.');
        }

        if ($model !== null && ! is_subclass_of($model, Model::class)) {
            throw new DefinitionException('Permission "'.$local.'" names the resource '.json_encode($model).', which is not an Eloquent model.');
        }

        $enumCase = null;

        if (is_array($case) && is_string($case['enum'] ?? null) && is_string($case['name'] ?? null) && is_subclass_of($case['enum'], BackedEnum::class)) {
            $value = constant($case['enum'].'::'.$case['name']);
            $enumCase = $value instanceof BackedEnum ? $value : null;
        }

        return new PermissionDefinition(
            local: $local,
            authority: PermissionAuthority::from($authority),
            label: $label,
            group: $group,
            description: $description,
            case: $enumCase,
            resourceModel: $model,
        );
    }

    /**
     * @param  ReflectionEnum<BackedEnum>  $enum
     * @return array{label: ?string, model: class-string<Model>|null}
     *
     * @throws DefinitionException
     */
    private static function resource(ReflectionEnum $enum, string $name): array
    {
        $attributes = $enum->getAttributes(Resource::class);
        $resource = $attributes === [] ? null : $attributes[0]->newInstance();

        if (! $resource instanceof Resource) {
            return ['label' => null, 'model' => null];
        }

        $model = $resource->model;

        if ($model !== null && ! is_subclass_of($model, Model::class)) {
            throw new DefinitionException(
                $name.' declares #[Resource] with the model '.json_encode($model).', which is not an Eloquent model.',
            );
        }

        return ['label' => $resource->label, 'model' => $model];
    }

    /**
     * @param  ReflectionEnum<BackedEnum>  $enum
     *
     * @throws DefinitionException
     */
    private static function authority(ReflectionEnum $enum, ReflectionClassConstant $case, string $name): PermissionAuthority
    {
        $onCase = self::modes($case->getAttributes());
        $selected = $onCase;

        if ($onCase === []) {
            $selected = self::modes($enum->getAttributes());
        }

        if (count($selected) !== 1) {
            $where = $onCase === [] ? 'the enum' : 'the case '.$case->getName();

            throw new DefinitionException(
                $name.' has '.(count($selected) === 0 ? 'no' : 'both').' authority attributes on '.$where
                .': declare #[RequiresGrant] or #[PolicyOnly], and a case replaces the enum.',
            );
        }

        return $selected[0] === RequiresGrant::class ? PermissionAuthority::Grants : PermissionAuthority::Policy;
    }

    /**
     * @param  list<ReflectionAttribute<object>>  $attributes
     * @return list<class-string>
     */
    private static function modes(array $attributes): array
    {
        $modes = [];

        foreach ($attributes as $attribute) {
            $class = $attribute->getName();

            if ($class === RequiresGrant::class || $class === PolicyOnly::class) {
                $modes[] = $class;
            }
        }

        return $modes;
    }

    private static function described(ReflectionClassConstant $case): ?Describe
    {
        $attributes = $case->getAttributes(Describe::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    private static function blank(?string $group): ?string
    {
        $group = $group === null ? null : trim($group, '/');

        return $group === '' ? null : $group;
    }
}
