<?php

namespace justinholtweb\bee\models;

use Craft;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\helpers\StringHelper;
use justinholtweb\bee\helpers\Props;

/**
 * A catalog source: "these elements become Recombee items, with these properties".
 *
 * Sources live in **project config**, not a table. Which entry types feed the recommender is part of
 * how the site is built, so it should arrive with a deploy rather than have to be re-clicked in
 * every environment. The consequence is that sources are read-only when `allowAdminChanges` is off,
 * which is correct.
 */
class Source extends Model
{
    /**
     * Properties Bee always sends, and therefore refuses to let a mapping redefine.
     *
     * These are not a convenience. `Reql::liveOnly()` filters every recommendation on `enabled`,
     * `postDate` and `expiryDate`, so if they were optional a merchant could switch them off and
     * quietly start recommending unpublished content.
     */
    public const RESERVED_PROPERTIES = [
        'title', 'url', 'imageUrl', 'itemType', 'siteId', 'sourceHandle',
        'enabled', 'postDate', 'expiryDate', 'updatedAt', 'slug',
    ];

    public ?string $uid = null;
    public string $name = '';
    public bool $enabled = true;

    /** Fully-qualified element class. */
    public string $elementType = 'craft\\elements\\Entry';

    /**
     * Which sections / product types / volumes / groups feed this source, by UID. Empty means all
     * of them for the element type.
     */
    public array $groupUids = [];

    /**
     * Which entry types, by UID. Only meaningful for entries, and empty means all.
     */
    public array $typeUids = [];

    /** Only sync elements with a live status. Disabled ones are deleted from Recombee instead. */
    public bool $liveOnly = true;

    /** @var PropertyMap[] */
    public array $properties = [];

    /** Sort order in the CP. */
    public int $sortOrder = 0;

    public function init(): void
    {
        parent::init();

        $this->uid ??= StringHelper::UUID();
        $this->properties = array_map(
            static fn($p) => $p instanceof PropertyMap ? $p : new PropertyMap($p),
            $this->properties,
        );
    }

    protected function defineRules(): array
    {
        return [
            [['name', 'elementType'], 'required'],
            [['elementType'], function(string $attribute) {
                if (!class_exists($this->$attribute) || !is_subclass_of($this->$attribute, ElementInterface::class)) {
                    $this->addError($attribute, Craft::t('bee', 'That is not an element type Bee can sync.'));
                }
            }],
            [['properties'], function(string $attribute) {
                $seen = [];

                foreach ($this->properties as $i => $property) {
                    if (!$property->validate()) {
                        foreach ($property->getErrors() as $errors) {
                            $this->addError($attribute, Craft::t('bee', 'Property {n}: {error}', ['n' => $i + 1, 'error' => reset($errors)]));
                        }
                    }

                    $key = strtolower($property->name);

                    if (isset($seen[$key])) {
                        $this->addError($attribute, Craft::t('bee', 'Two mappings both write to “{name}”.', ['name' => $property->name]));
                    }

                    $seen[$key] = true;
                }
            }],
        ];
    }

    /**
     * Whether an element belongs to this source.
     *
     * Deliberately does *not* consider status: a source has to claim an element it no longer wants
     * synced, otherwise nothing would know to delete the Recombee item when an entry is disabled.
     */
    public function matches(ElementInterface $element): bool
    {
        if (!$element instanceof $this->elementType) {
            return false;
        }

        if ($this->groupUids !== [] && !in_array($this->groupUidFor($element), $this->groupUids, true)) {
            return false;
        }

        if ($this->typeUids !== []) {
            $typeUid = $this->typeUidFor($element);

            if ($typeUid === null || !in_array($typeUid, $this->typeUids, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a matched element should currently exist in Recombee.
     */
    public function includes(ElementInterface $element): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if ($this->liveOnly && $element->getStatus() !== 'live' && $element->getStatus() !== 'enabled') {
            return false;
        }

        return true;
    }

    /**
     * The section / volume / product type / category group UID for an element, whatever it is.
     */
    public function groupUidFor(ElementInterface $element): ?string
    {
        foreach (['getSection', 'getVolume', 'getGroup', 'getType'] as $getter) {
            if (method_exists($element, $getter)) {
                try {
                    $group = $element->$getter();
                } catch (\Throwable) {
                    continue;
                }

                if ($group !== null && isset($group->uid)) {
                    return $group->uid;
                }
            }
        }

        return null;
    }

    public function typeUidFor(ElementInterface $element): ?string
    {
        if (!method_exists($element, 'getType')) {
            return null;
        }

        try {
            return $element->getType()?->uid;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Every property this source declares, built-ins included, as `name => type`.
     */
    public function declaredProperties(): array
    {
        $declared = [
            'title' => Props::TYPE_STRING,
            'url' => Props::TYPE_STRING,
            'imageUrl' => Props::TYPE_IMAGE,
            'itemType' => Props::TYPE_STRING,
            'siteId' => Props::TYPE_INT,
            'sourceHandle' => Props::TYPE_STRING,
            'slug' => Props::TYPE_STRING,
            'enabled' => Props::TYPE_BOOLEAN,
            'postDate' => Props::TYPE_TIMESTAMP,
            'expiryDate' => Props::TYPE_TIMESTAMP,
            'updatedAt' => Props::TYPE_TIMESTAMP,
        ];

        foreach ($this->properties as $property) {
            if ($property->enabled) {
                $declared[$property->name] = $property->type;
            }
        }

        return $declared;
    }

    public function getElementDisplayName(): string
    {
        return $this->elementType::displayName();
    }

    /**
     * Project-config shape. `uid` is the key, not a value.
     */
    public function toConfig(): array
    {
        return [
            'name' => $this->name,
            'enabled' => $this->enabled,
            'elementType' => $this->elementType,
            'groupUids' => array_values($this->groupUids),
            'typeUids' => array_values($this->typeUids),
            'liveOnly' => $this->liveOnly,
            'sortOrder' => $this->sortOrder,
            'properties' => array_map(static fn(PropertyMap $p) => [
                'name' => $p->name,
                'type' => $p->type,
                'kind' => $p->kind,
                'value' => $p->value,
                'enabled' => $p->enabled,
            ], array_values($this->properties)),
        ];
    }
}
