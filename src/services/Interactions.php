<?php

namespace justinholtweb\bee\services;

use Craft;
use craft\base\ElementInterface;
use DateTime;
use DateTimeInterface;
use justinholtweb\bee\errors\ApiException;
use justinholtweb\bee\helpers\Ids;
use justinholtweb\bee\models\Interaction;
use justinholtweb\bee\Plugin;
use yii\base\Component;
use yii\base\Event;

/**
 * The only place an interaction is sent to Recombee.
 *
 * Twig, the front-end runtime, the Commerce hooks and the console backfill all end up in `record()`
 * or `recordMany()`. Consent, edition gating, user resolution, attribution lookup and the fail-open
 * guarantee therefore happen exactly once and cannot drift apart.
 *
 * Everything here fails open. An interaction is telemetry: losing one is a rounding error, and
 * taking down a product page or a checkout to avoid losing one is not a trade anybody would make.
 */
class Interactions extends Component
{
    /**
     * Raised before an interaction is sent. Handlers may set `isValid = false` to drop it — the
     * escape hatch for "don't track staff", "don't track this section", and every other site-
     * specific rule that would otherwise need a setting.
     */
    public const EVENT_BEFORE_RECORD = 'beforeRecord';

    /** Keys recorded this request, so a double-fired beacon does not become two interactions. */
    private array $seen = [];

    // ─── The funnel ──────────────────────────────────────────────────────────────────────────

    public function record(Interaction $interaction): bool
    {
        if (!$this->permits($interaction)) {
            return false;
        }

        $key = $interaction->dedupeKey();

        if (isset($this->seen[$key])) {
            return false;
        }

        $this->seen[$key] = true;

        try {
            Plugin::getInstance()->getClient()->post(
                $interaction->path(),
                $interaction->body(),
                [],
                ['label' => $interaction->kind . ' ' . $interaction->itemId],
            );

            return true;
        } catch (ApiException $e) {
            // Recombee answers 409 when this exact interaction already exists. That is the retry
            // protection doing its job, not a failure.
            if ($e->isDuplicate()) {
                return true;
            }

            Craft::warning(sprintf('Bee could not record a %s: %s', $interaction->kind, $e->getMessage()), 'bee');

            return false;
        } catch (\Throwable $e) {
            Craft::warning('Bee could not record an interaction: ' . $e->getMessage(), 'bee');

            return false;
        }
    }

    /**
     * Send many interactions in batches. Used by the Commerce order hook (one purchase per line
     * item) and by the historic backfill.
     *
     * @param Interaction[] $interactions
     * @return array{sent: int, skipped: int, failed: int}
     */
    public function recordMany(array $interactions, bool $dedupe = true): array
    {
        $tally = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
        $requests = [];

        foreach ($interactions as $interaction) {
            if (!$this->permits($interaction)) {
                $tally['skipped']++;
                continue;
            }

            if ($dedupe) {
                $key = $interaction->dedupeKey();

                if (isset($this->seen[$key])) {
                    $tally['skipped']++;
                    continue;
                }

                $this->seen[$key] = true;
            }

            $requests[] = [
                'method' => 'POST',
                'path' => '/' . $interaction->path(),
                'params' => $interaction->body(),
            ];
        }

        if ($requests === []) {
            return $tally;
        }

        try {
            foreach (Plugin::getInstance()->getClient()->batch($requests) as $result) {
                $code = (int)($result['code'] ?? 0);

                // 409 inside a batch is the same duplicate-protection signal as it is on its own.
                if (($code >= 200 && $code < 300) || $code === 409) {
                    $tally['sent']++;
                } else {
                    $tally['failed']++;
                }
            }
        } catch (\Throwable $e) {
            Craft::warning('Bee could not record a batch of interactions: ' . $e->getMessage(), 'bee');
            $tally['failed'] += count($requests);
        }

        return $tally;
    }

    // ─── Convenience constructors ────────────────────────────────────────────────────────────

    public function detailView(ElementInterface|string $item, array $options = []): bool
    {
        return $this->record($this->build(Interaction::DETAIL_VIEW, $item, $options));
    }

    public function purchase(ElementInterface|string $item, array $options = []): bool
    {
        return $this->record($this->build(Interaction::PURCHASE, $item, $options));
    }

    public function cartAddition(ElementInterface|string $item, array $options = []): bool
    {
        return $this->record($this->build(Interaction::CART_ADDITION, $item, $options));
    }

    public function bookmark(ElementInterface|string $item, array $options = []): bool
    {
        return $this->record($this->build(Interaction::BOOKMARK, $item, $options));
    }

    public function rating(ElementInterface|string $item, float $rating, array $options = []): bool
    {
        return $this->record($this->build(Interaction::RATING, $item, $options + ['rating' => $rating]));
    }

    public function viewPortion(ElementInterface|string $item, float $portion, array $options = []): bool
    {
        return $this->record($this->build(Interaction::VIEW_PORTION, $item, $options + ['portion' => $portion]));
    }

    /**
     * Build an interaction, filling in the visitor and the attribution.
     *
     * `userId` may be passed in — the console and the Commerce hooks have to, because they run
     * outside the visitor's request — but it is never taken from a *client*. See `Identity`.
     */
    public function build(string $kind, ElementInterface|string $item, array $options = []): Interaction
    {
        $itemId = $item instanceof ElementInterface ? (Ids::forElement($item) ?? '') : $item;
        $userId = $options['userId'] ?? Plugin::getInstance()->getIdentity()->currentUserId();

        $interaction = new Interaction([
            'kind' => $kind,
            'itemId' => $itemId,
            'userId' => (string)$userId,
            'timestamp' => $this->timestamp($options['timestamp'] ?? null),
        ]);

        foreach (['duration', 'amount', 'price', 'profit', 'rating', 'portion', 'timeSpent', 'sessionId', 'cascadeCreate'] as $key) {
            if (array_key_exists($key, $options) && $options[$key] !== null) {
                $interaction->$key = $options[$key];
            }
        }

        $interaction->recommId = $options['recommId']
            ?? Plugin::getInstance()->getRecommendations()->attributionFor((string)$userId, $itemId);

        return $interaction;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Every reason an interaction might not be sent, in one place.
     */
    private function permits(Interaction $interaction): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->trackingEnabled || !$settings->isConfigured()) {
            return false;
        }

        if ($interaction->userId === '' || $interaction->itemId === '') {
            return false;
        }

        if (!$interaction->isAvailable()) {
            return false;
        }

        if (!$interaction->validate()) {
            Craft::warning(sprintf(
                'Bee refused an invalid %s: %s',
                $interaction->kind,
                implode('; ', array_merge(...array_values($interaction->getErrors()))),
            ), 'bee');

            return false;
        }

        $event = new \justinholtweb\bee\events\InteractionEvent(['interaction' => $interaction]);
        $this->trigger(self::EVENT_BEFORE_RECORD, $event);

        return $event->isValid;
    }

    private function timestamp(mixed $value): DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        if (is_numeric($value)) {
            return (new DateTime())->setTimestamp((int)$value);
        }

        if (is_string($value) && $value !== '' && ($ts = strtotime($value)) !== false) {
            return (new DateTime())->setTimestamp($ts);
        }

        return new DateTime();
    }
}
