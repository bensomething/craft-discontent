<?php

declare(strict_types=1);

namespace bensomething\discontent\Support;

use CraftCms\Cms\Asset\Data\Volume;
use CraftCms\Cms\Component\Contracts\Chippable;
use CraftCms\Cms\Component\Contracts\CpEditable;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Field\Contracts\FieldInterface;
use CraftCms\Cms\FieldLayout\FieldLayout;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Fields;

/**
 * One pass over every field layout, giving two things per field UID: the layouts it's
 * used in (their owners), and its instances.
 *
 * An instance is a field's appearance in a layout under its own label or handle, so the
 * same field can read as "Intro" in one entry type and "Body" in another. Only
 * instances that differ from the field are kept: one using the field's own name and
 * handle is the field as it comes.
 *
 * Layouts are collected from the element types (`fieldLayouts()`) rather than
 * `Fields::getAllLayouts()`, because that's what attaches their providers: the entry
 * type, volume or other thing a layout belongs to, and the only thing that can name it
 * or link to it. Craft does the same when it lists a field's usages. Layouts no element
 * type reports are absent, so callers fall back to a count.
 */
class FieldLayoutScan
{
    /** @var array<string, array<string, LayoutOwner>> Field UID => layout key => owner */
    private array $owners = [];

    /** @var array<string, list<array{label: ?string, handle: string, owner: LayoutOwner}>> */
    private array $instances = [];

    /** @var array<int, int>|null Field ID => how many layouts use it */
    private ?array $usageCounts = null;

    private bool $scanned = false;

    /**
     * How many layouts use the field: Craft's own count, which sees every layout, where
     * the scan only sees those an element type reports.
     */
    public function usageCount(FieldInterface $field): int
    {
        $this->usageCounts ??= Fields::getAllFields()
            ->mapWithKeys(fn (FieldInterface $f) => [(int) $f->id => Fields::findFieldUsages($f)->count()])
            ->all();

        return $this->usageCounts[(int) $field->id] ?? 0;
    }

    /** @return list<LayoutOwner> The layouts the field is used in, as far as the scan sees. */
    public function owners(string $fieldUid): array
    {
        $this->scan();

        return array_values($this->owners[$fieldUid] ?? []);
    }

    /**
     * The field's instances, by owner then label. A null label means the instance keeps
     * the field's name and only changes its handle.
     *
     * @return list<array{label: ?string, handle: string, owner: LayoutOwner}>
     */
    public function instances(string $fieldUid): array
    {
        $this->scan();

        return $this->instances[$fieldUid] ?? [];
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

        $fieldsByUid = Fields::getAllFields()->keyBy('uid');
        $seen = [];

        /** @var class-string<ElementInterface> $elementType */
        foreach (Elements::getAllElementTypes() as $elementType) {
            foreach ($elementType::fieldLayouts(null) as $layout) {
                // The same layout can be reachable from more than one source.
                $layoutKey = $layout->uid ?? (string) spl_object_id($layout);
                if (isset($seen[$layoutKey])) {
                    continue;
                }
                $seen[$layoutKey] = true;

                $owner = $this->owner($layout, $elementType);

                foreach ($layout->getCustomFieldElements() as $element) {
                    $field = $fieldsByUid->get($element->getFieldUid());
                    $handle = $element->attribute();

                    if ($field === null || $handle === '') {
                        continue;
                    }

                    // Keyed by layout: a field used twice in one layout is still only
                    // used by one layout.
                    $this->owners[$field->uid][$layoutKey] = $owner;

                    // The field as it comes. An instance keeping the handle but taking a
                    // new label is still worth showing.
                    $label = $element->label();
                    if ($handle === $field->handle && ($label === null || $label === $field->name)) {
                        continue;
                    }

                    $this->instances[$field->uid][] = [
                        'label' => is_string($label) ? $label : null,
                        'handle' => $handle,
                        'owner' => $owner,
                    ];
                }
            }
        }

        foreach ($this->instances as &$fieldInstances) {
            usort($fieldInstances, fn (array $a, array $b) => strcasecmp($a['owner']->label, $b['owner']->label)
                ?: strcasecmp((string) $a['label'], (string) $b['label']));
        }
    }

    /** @param class-string<ElementInterface> $elementType */
    private function owner(FieldLayout $layout, string $elementType): LayoutOwner
    {
        $provider = $layout->provider;

        if (! $provider instanceof Chippable) {
            // Some layouts have no provider (the users layout, say). They still have a
            // home worth naming, just nothing to link to.
            return new LayoutOwner(label: $elementType::displayName());
        }

        // The screens Craft can open in a slideout, where the layout designer lives: an
        // instance's label and handle are edited there, not on the field.
        $slideoutUrl = match (true) {
            $provider instanceof EntryType => route('craft.cp.settings.entry-types.edit', ['entryType' => $provider->id]),
            $provider instanceof Volume => route('craft.cp.settings.assets.volumes.edit', ['volumeId' => $provider->id]),
            default => null,
        };

        $url = $provider instanceof CpEditable ? $provider->getCpEditUrl() : null;

        // Those screens keep the layout designer on a Content tab, which Craft's tabs
        // open from the URL hash.
        if ($url !== null && $slideoutUrl !== null) {
            $url .= '#ui-tab-content-tab';
        }

        return new LayoutOwner(
            label: $provider->getUiLabel(),
            provider: $provider,
            url: $url,
            slideoutUrl: $slideoutUrl,
        );
    }
}
