<?php

namespace justinholtweb\bee\models;

use ArrayIterator;
use Countable;
use craft\base\ElementInterface;
use IteratorAggregate;
use justinholtweb\bee\Plugin;
use Traversable;

/**
 * What a recommendation call hands back to a template.
 *
 * Iterating it yields **Craft elements**, not IDs — that is the whole point of a Craft plugin over
 * a REST wrapper, and it means `{% for product in recs %}` works with every field, transform and
 * URL the template already knows how to use.
 *
 * The `recommId` rides along, because a recommendation nobody can attribute is a recommendation
 * Recombee cannot learn from.
 */
class RecommendationSet implements IteratorAggregate, Countable
{
    /** @var array<int, array{id: string, values: array}> */
    public array $items = [];

    public ?string $recommId = null;

    public ?string $abGroup = null;

    public ?string $scenario = null;

    /** 'user', 'item', 'segment' or 'search'. */
    public string $kind = 'user';

    public ?string $sourceItemId = null;

    /** How many more pages Recombee will serve for this recommId. */
    public int $nextCalls = 0;

    /** The site the elements should be resolved in. */
    public ?int $siteId = null;

    /** Set when the request failed and Bee fell back to an empty set rather than throwing. */
    public bool $failed = false;

    private ?array $elements = null;

    public function __construct(array $config = [])
    {
        foreach ($config as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    /**
     * Build from a raw Recombee response.
     */
    public static function fromResponse(mixed $response, array $context = []): self
    {
        $set = new self($context);

        if (!is_array($response)) {
            return $set;
        }

        $set->recommId = $response['recommId'] ?? null;
        $set->abGroup = $response['abGroup'] ?? null;
        $set->nextCalls = (int)($response['numberNextRecommsCalls'] ?? 0);

        foreach ($response['recomms'] ?? [] as $recomm) {
            if (is_string($recomm)) {
                $set->items[] = ['id' => $recomm, 'values' => []];
            } elseif (isset($recomm['id'])) {
                $set->items[] = ['id' => (string)$recomm['id'], 'values' => $recomm['values'] ?? []];
            }
        }

        return $set;
    }

    /**
     * The Recombee item IDs, in the order Recombee returned them.
     *
     * @return string[]
     */
    public function ids(): array
    {
        return array_column($this->items, 'id');
    }

    /**
     * The properties Recombee returned alongside each item, keyed by item ID. Populated only when
     * the request asked for them.
     */
    public function values(): array
    {
        return array_column($this->items, 'values', 'id');
    }

    /**
     * The recommended items as Craft elements, in Recombee's order, with anything unresolvable
     * dropped.
     *
     * Items *do* go missing: an element deleted in Craft is still in Recombee until the next sync,
     * and the honest answer is to show five recommendations rather than a broken sixth.
     *
     * @return ElementInterface[]
     */
    public function elements(): array
    {
        return $this->elements ??= Plugin::getInstance()->getRecommendations()
            ->resolveElements($this->ids(), $this->siteId);
    }

    /**
     * The element for one item ID, or null.
     */
    public function element(string $itemId): ?ElementInterface
    {
        foreach ($this->elements() as $element) {
            if (\justinholtweb\bee\helpers\Ids::forElement($element) === $itemId) {
                return $element;
            }
        }

        return null;
    }

    /**
     * The markup attributes that let the front-end runtime attribute a click back to this set.
     *
     * Rendered onto the link or card for a recommended item:
     *
     *     <a href="…" {{ recs.attributes(product) }}>
     */
    public function attributes(ElementInterface|string $item): array
    {
        $itemId = $item instanceof ElementInterface ? \justinholtweb\bee\helpers\Ids::forElement($item) : $item;

        return array_filter([
            'data-bee-recomm-id' => $this->recommId,
            'data-bee-item-id' => $itemId,
        ], static fn($v) => $v !== null && $v !== '');
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function hasMore(): bool
    {
        return $this->nextCalls > 0 && $this->recommId !== null;
    }

    /**
     * The next page of the same recommendation. Pro.
     */
    public function next(?int $count = null): self
    {
        return Plugin::getInstance()->getRecommendations()->next($this->recommId, $count, $this->siteId);
    }

    public function count(): int
    {
        return count($this->elements());
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->elements());
    }

    /**
     * So `{{ recs }}` in a template is not a fatal.
     */
    public function __toString(): string
    {
        return implode(', ', $this->ids());
    }
}
