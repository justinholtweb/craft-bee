<?php

namespace justinholtweb\bee\services;

use Craft;
use craft\base\ElementInterface;
use craft\helpers\ArrayHelper;
use craft\helpers\StringHelper;
use justinholtweb\bee\models\Source;
use yii\base\Component;

/**
 * Catalog sources, stored in project config.
 *
 * There is no mirror table: project config *is* the store. That keeps a deploy authoritative and
 * removes the whole class of bug where the table and the YAML disagree about what is being synced.
 */
class Sources extends Component
{
    public const CONFIG_KEY = 'bee.sources';

    /** @var Source[]|null */
    private ?array $sources = null;

    /**
     * @return Source[] keyed by UID, in sort order
     */
    public function all(): array
    {
        if ($this->sources !== null) {
            return $this->sources;
        }

        $configs = Craft::$app->getProjectConfig()->get(self::CONFIG_KEY) ?? [];
        $sources = [];

        foreach ($configs as $uid => $config) {
            if (!is_array($config)) {
                continue;
            }

            $sources[$uid] = new Source($config + ['uid' => $uid]);
        }

        uasort($sources, static fn(Source $a, Source $b) => [$a->sortOrder, $a->name] <=> [$b->sortOrder, $b->name]);

        return $this->sources = $sources;
    }

    /**
     * @return Source[]
     */
    public function enabled(): array
    {
        return array_filter($this->all(), static fn(Source $s) => $s->enabled);
    }

    public function get(string $uid): ?Source
    {
        return $this->all()[$uid] ?? null;
    }

    /**
     * The source that claims an element, or null.
     *
     * First match wins, in sort order, so a merchant can put a narrow source above a broad one and
     * have it take precedence — the same way Craft's own routing rules read.
     */
    public function forElement(ElementInterface $element): ?Source
    {
        foreach ($this->all() as $source) {
            if ($source->matches($element)) {
                return $source;
            }
        }

        return null;
    }

    /**
     * Every source that could claim an element of this class, used to build the console command's
     * element queries.
     *
     * @return Source[]
     */
    public function forElementType(string $elementType): array
    {
        return array_filter($this->all(), static fn(Source $s) => $s->elementType === $elementType);
    }

    /**
     * The element types any enabled source refers to.
     */
    public function activeElementTypes(): array
    {
        return array_values(array_unique(array_map(
            static fn(Source $s) => $s->elementType,
            $this->enabled(),
        )));
    }

    public function save(Source $source, bool $runValidation = true): bool
    {
        if ($runValidation && !$source->validate()) {
            return false;
        }

        $source->uid ??= StringHelper::UUID();

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_KEY . '.' . $source->uid,
            $source->toConfig(),
            "Save the “{$source->name}” Bee catalog source",
        );

        $this->sources = null;

        return true;
    }

    public function delete(string $uid): bool
    {
        $source = $this->get($uid);

        if ($source === null) {
            return false;
        }

        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_KEY . '.' . $uid,
            "Delete the “{$source->name}” Bee catalog source",
        );

        $this->sources = null;

        return true;
    }

    public function reorder(array $uids): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        foreach (array_values($uids) as $order => $uid) {
            $source = $this->get($uid);

            if ($source === null) {
                continue;
            }

            $source->sortOrder = $order;
            $projectConfig->set(self::CONFIG_KEY . '.' . $uid, $source->toConfig(), 'Reorder Bee catalog sources');
        }

        $this->sources = null;

        return true;
    }

    /**
     * Every property name and type across every enabled source.
     *
     * Recombee has one flat property namespace per database, so two sources that both declare
     * `price` must agree on its type. Conflicts are surfaced here rather than discovered when the
     * second source's items start being rejected.
     *
     * @return array{properties: array<string,string>, conflicts: array<string,array<string>>}
     */
    public function declaredProperties(): array
    {
        $properties = [];
        $conflicts = [];

        foreach ($this->enabled() as $source) {
            foreach ($source->declaredProperties() as $name => $type) {
                if (isset($properties[$name]) && $properties[$name] !== $type) {
                    $conflicts[$name] = array_values(array_unique(
                        ArrayHelper::merge($conflicts[$name] ?? [$properties[$name]], [$type]),
                    ));
                    continue;
                }

                $properties[$name] = $type;
            }
        }

        ksort($properties);

        return ['properties' => $properties, 'conflicts' => $conflicts];
    }

    /**
     * Called from the project-config change handlers. The memo is the only state here.
     */
    public function invalidate(): void
    {
        $this->sources = null;
    }
}
