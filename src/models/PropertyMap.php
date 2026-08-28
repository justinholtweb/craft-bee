<?php

namespace justinholtweb\bee\models;

use Craft;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\helpers\StringHelper;
use justinholtweb\bee\helpers\Props;
use justinholtweb\bee\Plugin;

/**
 * One "this Craft value becomes that Recombee property" rule.
 *
 * Kept deliberately small. Anything a mapping cannot express is expressed with a Twig snippet,
 * which is evaluated in a sandbox against the element — because the alternative is an expression
 * language nobody else knows, in a plugin whose users already know Twig.
 */
class PropertyMap extends Model
{
    /** A property of the element itself: `title`, `slug`, `postDate`, `url`… */
    public const KIND_ATTRIBUTE = 'attribute';

    /** A custom field handle. */
    public const KIND_FIELD = 'field';

    /** One of the canned mappers in `Catalog::special()` — price, stock, categories and friends. */
    public const KIND_SPECIAL = 'special';

    /** An inline Twig template rendered with `element` in scope. */
    public const KIND_TWIG = 'twig';

    public string $name = '';
    public string $type = Props::TYPE_STRING;
    public string $kind = self::KIND_FIELD;
    public string $value = '';

    /**
     * Whether Recombee should be told about this property at all. Turning a mapping off stops it
     * being sent without losing the mapping or deleting the property (and its history) in Recombee.
     */
    public bool $enabled = true;

    protected function defineRules(): array
    {
        return [
            [['name', 'type', 'kind'], 'required'],
            [['type'], 'in', 'range' => Props::TYPES],
            [['kind'], 'in', 'range' => [self::KIND_ATTRIBUTE, self::KIND_FIELD, self::KIND_SPECIAL, self::KIND_TWIG]],
            [['name'], function(string $attribute) {
                if (!Props::isValidName($this->$attribute)) {
                    $this->addError($attribute, Craft::t('bee', 'Property names must start with a letter or underscore and contain only letters, numbers and underscores.'));
                }
            }],
            [['name'], function(string $attribute) {
                if (in_array(strtolower($this->$attribute), array_map('strtolower', Source::RESERVED_PROPERTIES), true)) {
                    $this->addError($attribute, Craft::t('bee', '“{name}” is one of the properties Bee maintains itself.', ['name' => $this->$attribute]));
                }
            }],
            [['value'], 'required', 'when' => fn(self $m) => $m->kind !== self::KIND_SPECIAL || $m->value === ''],
        ];
    }

    /**
     * Read the mapped value off an element, before type coercion.
     */
    public function extract(ElementInterface $element): mixed
    {
        try {
            return match ($this->kind) {
                self::KIND_ATTRIBUTE => $this->readAttribute($element),
                self::KIND_FIELD => $element->getFieldValue($this->value),
                self::KIND_SPECIAL => Plugin::getInstance()->getCatalog()->special($this->value, $element),
                self::KIND_TWIG => $this->renderTwig($element),
                default => null,
            };
        } catch (\Throwable $e) {
            // One broken mapping must not stop the other forty properties reaching Recombee.
            Craft::warning(sprintf(
                'Bee could not read “%s” (%s: %s) from element %d: %s',
                $this->name,
                $this->kind,
                $this->value,
                $element->id,
                $e->getMessage(),
            ), 'bee');

            return null;
        }
    }

    private function readAttribute(ElementInterface $element): mixed
    {
        // `url` is a getter, not an attribute, and so are several of the useful ones.
        $getter = 'get' . ucfirst($this->value);

        if (method_exists($element, $getter)) {
            return $element->$getter();
        }

        return $element->{$this->value} ?? null;
    }

    private function renderTwig(ElementInterface $element): mixed
    {
        $rendered = Craft::$app->getView()->renderObjectTemplate($this->value, $element, [
            'element' => $element,
        ]);

        // A set built in Twig comes back as a string; splitting on newlines is the least surprising
        // way to let a template produce several values.
        if ($this->type === Props::TYPE_SET || $this->type === Props::TYPE_IMAGE_LIST) {
            return array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', $rendered) ?: [])));
        }

        return StringHelper::trim($rendered);
    }
}
