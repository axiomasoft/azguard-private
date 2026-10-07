<?php

declare(strict_types=1);

namespace AzGuard\Directories;

use AzGuard\Contracts\Subjects\SubjectDirectory;
use AzGuard\Kernel\Identity\SubjectRef;

/**
 * The subject directories of a panel with several subject models: a search reads each directory once in
 * declaration order, a description goes to the directory of the subject type.
 *
 * @internal
 */
final readonly class PanelSubjectDirectory implements SubjectDirectory
{
    /** @param array<string, SubjectDirectory> $byType directory of each morph type, in declaration order */
    public function __construct(private array $byType) {}

    /**
     * @return list<SubjectOption>
     */
    public function search(string $term, LookupContext $lookup, int $limit, ?string $type = null): array
    {
        $options = [];

        foreach ($this->unique() as $directory) {
            if ($limit - count($options) < 1) {
                break;
            }
            array_push($options, ...$directory->search($term, $lookup, $limit - count($options), $type));
        }

        return array_slice($options, 0, max($limit, 0));
    }

    public function describe(SubjectRef $subject, LookupContext $lookup): ?SubjectOption
    {
        return ($this->byType[$subject->type()] ?? null)?->describe($subject, $lookup);
    }

    /** @return list<SubjectDirectory> */
    private function unique(): array
    {
        $unique = [];

        foreach ($this->byType as $directory) {
            $unique[spl_object_id($directory)] = $directory;
        }

        return array_values($unique);
    }
}
