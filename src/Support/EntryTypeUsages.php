<?php

declare(strict_types=1);

namespace bensomething\discontent\Support;

use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Field\Contracts\ElementContainerFieldInterface;
use CraftCms\Cms\Field\Matrix;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sections;

/**
 * Where each entry type is used, by what kind of thing, and under which other names.
 *
 * Collected the way Craft's own Entry Types index does: each section's entry types,
 * then each nested-entry field's (Matrix, CKEditor, …). One pass, built on first use
 * and kept for the request.
 *
 * An "instance" is a usage that renames the entry type: a section or field can give it
 * its own name, handle and description. Craft sets {@see EntryType::$original} on any
 * override, including ones that change nothing a person would read (the group), so the
 * readable values are compared directly.
 */
class EntryTypeUsages
{
    public const string KIND_SECTION = 'section';

    public const string KIND_MATRIX = 'matrix';

    public const string KIND_CKEDITOR = 'ckeditor';

    /** Any other nested-entry field type. */
    public const string KIND_FIELD = 'field';

    /** @var array<int, list<Section|ElementContainerFieldInterface>> */
    private array $usages = [];

    /** @var array<int, array<string, true>> */
    private array $kinds = [];

    /** @var array<int, list<array{label: string, handle: string, owner: Section|ElementContainerFieldInterface}>> */
    private array $instances = [];

    private bool $scanned = false;

    /** @return list<Section|ElementContainerFieldInterface> What uses the entry type. */
    public function usages(int $entryTypeId): array
    {
        $this->scan();

        return $this->usages[$entryTypeId] ?? [];
    }

    public function isUsed(int $entryTypeId): bool
    {
        return $this->usages($entryTypeId) !== [];
    }

    /** Whether something of this kind ({@see KIND_SECTION} etc.) uses the entry type. */
    public function isUsedAs(int $entryTypeId, string $kind): bool
    {
        $this->scan();

        return isset($this->kinds[$entryTypeId][$kind]);
    }

    /** @return list<string> The kinds of thing using any entry type, in filter order. */
    public function kindsInUse(): array
    {
        $this->scan();

        $inUse = array_merge(...array_map(array_keys(...), array_values($this->kinds)));

        return array_values(array_intersect(
            [self::KIND_SECTION, self::KIND_MATRIX, self::KIND_CKEDITOR, self::KIND_FIELD],
            $inUse,
        ));
    }

    /**
     * The usages that rename the entry type, by label.
     *
     * @return list<array{label: string, handle: string, owner: Section|ElementContainerFieldInterface}>
     */
    public function instances(int $entryTypeId): array
    {
        $this->scan();

        return $this->instances[$entryTypeId] ?? [];
    }

    public function hasInstances(): bool
    {
        $this->scan();

        return $this->instances !== [];
    }

    private function scan(): void
    {
        if ($this->scanned) {
            return;
        }

        $this->scanned = true;

        foreach (Sections::getAllSections() as $section) {
            foreach ($section->getEntryTypes() as $entryType) {
                $this->record($entryType, $section, self::KIND_SECTION);
            }
        }

        foreach (Fields::getNestedEntryFieldTypes() as $type) {
            $kind = self::kindOf($type);

            /** @var iterable<ElementContainerFieldInterface> $fields */
            $fields = Fields::getFieldsByType($type);

            foreach ($fields as $field) {
                foreach ($field->getFieldLayoutProviders() as $provider) {
                    if ($provider instanceof EntryType) {
                        $this->record($provider, $field, $kind);
                    }
                }
            }
        }

        foreach ($this->instances as &$typeInstances) {
            usort($typeInstances, fn (array $a, array $b) => strcasecmp($a['label'], $b['label']));
        }
    }

    /**
     * Matrix by class. CKEditor by name, since it's a separate package that may not be
     * installed (or yet released for Craft 6) and whose class Craft 6 may rename.
     *
     * @param  class-string  $type
     */
    private static function kindOf(string $type): string
    {
        return match (true) {
            is_a($type, Matrix::class, true) => self::KIND_MATRIX,
            str_contains(strtolower($type), 'ckeditor') => self::KIND_CKEDITOR,
            default => self::KIND_FIELD,
        };
    }

    private function record(EntryType $entryType, Section|ElementContainerFieldInterface $owner, string $kind): void
    {
        $id = (int) $entryType->id;

        $this->usages[$id][] = $owner;
        $this->kinds[$id][$kind] = true;

        $original = $entryType->original;

        if (
            $original === null || (
                $entryType->name === $original->name &&
                $entryType->handle === $original->handle &&
                $entryType->description === $original->description
            )
        ) {
            return;
        }

        $this->instances[$id][] = [
            'label' => $entryType->getUiLabel(),
            'handle' => (string) $entryType->handle,
            'owner' => $owner,
        ];
    }
}
