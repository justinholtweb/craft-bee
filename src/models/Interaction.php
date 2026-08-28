<?php

namespace justinholtweb\bee\models;

use craft\base\Model;
use DateTimeInterface;
use justinholtweb\bee\Plugin;

/**
 * The one interaction shape.
 *
 * A Twig call, a browser beacon, a Commerce order hook and a console backfill all become one of
 * these before anything Recombee-specific happens. That is what stops the six event types growing
 * six slightly different notions of consent, attribution and timestamps.
 */
class Interaction extends Model
{
    public const DETAIL_VIEW = 'detailview';
    public const PURCHASE = 'purchase';
    public const CART_ADDITION = 'cartaddition';
    public const BOOKMARK = 'bookmark';
    public const RATING = 'rating';
    public const VIEW_PORTION = 'viewportion';

    public const KINDS = [
        self::DETAIL_VIEW,
        self::PURCHASE,
        self::CART_ADDITION,
        self::BOOKMARK,
        self::RATING,
        self::VIEW_PORTION,
    ];

    /**
     * The two Lite ships with.
     *
     * Detail views and purchases are the pair that make a recommender work at all — everything else
     * sharpens it. A Lite install is a real, useful integration, not a teaser.
     */
    public const LITE_KINDS = [self::DETAIL_VIEW, self::PURCHASE];

    public string $kind = self::DETAIL_VIEW;
    public string $userId = '';
    public string $itemId = '';
    public ?DateTimeInterface $timestamp = null;

    /** The recommendation this interaction followed, if any. */
    public ?string $recommId = null;

    /** Seconds spent on a detail view. */
    public ?int $duration = null;

    /** Units purchased or added to a cart. */
    public ?float $amount = null;

    /** Unit price. */
    public ?float $price = null;

    /** Unit profit, when the merchant knows it. */
    public ?float $profit = null;

    /** -1.0 … 1.0 */
    public ?float $rating = null;

    /** 0.0 … 1.0 */
    public ?float $portion = null;

    public ?int $timeSpent = null;

    public ?string $sessionId = null;

    /** Create the user and item in Recombee if they are not there yet. */
    public bool $cascadeCreate = true;

    protected function defineRules(): array
    {
        return [
            [['kind', 'userId', 'itemId'], 'required'],
            [['kind'], 'in', 'range' => self::KINDS],
            [['rating'], 'number', 'min' => -1, 'max' => 1],
            [['portion'], 'number', 'min' => 0, 'max' => 1],
            [['amount', 'price'], 'number', 'min' => 0],
            [['duration', 'timeSpent'], 'integer', 'min' => 0],
        ];
    }

    /**
     * Recombee's endpoint for this kind. Pluralised, trailing slash, exactly as the API wants it.
     */
    public function path(): string
    {
        return $this->kind . 's/';
    }

    /**
     * The request body.
     *
     * Timestamps go out as UTC epoch seconds rather than ISO strings: Craft hands back site-local
     * DateTimes, and an ISO string with the wrong offset produces interactions that are silently
     * hours out of order — which is exactly the signal a recommender is reading.
     */
    public function body(): array
    {
        $body = [
            'userId' => $this->userId,
            'itemId' => $this->itemId,
            'cascadeCreate' => $this->cascadeCreate,
        ];

        if ($this->timestamp !== null) {
            $body['timestamp'] = $this->timestamp->getTimestamp();
        }

        if ($this->recommId !== null && $this->recommId !== '') {
            $body['recommId'] = $this->recommId;
        }

        foreach ($this->kindFields() as $field => $key) {
            if ($this->$field !== null) {
                $body[$key] = $this->$field;
            }
        }

        return $body;
    }

    /**
     * Which of the extra fields this kind actually carries. Sending `duration` on a purchase is a
     * 400 from Recombee, not an ignored field.
     */
    private function kindFields(): array
    {
        return match ($this->kind) {
            self::DETAIL_VIEW => ['duration' => 'duration'],
            self::PURCHASE => ['amount' => 'amount', 'price' => 'price', 'profit' => 'profit'],
            self::CART_ADDITION => ['amount' => 'amount', 'price' => 'price'],
            self::RATING => ['rating' => 'rating'],
            self::VIEW_PORTION => ['portion' => 'portion', 'sessionId' => 'sessionId'],
            default => [],
        };
    }

    public function isAvailable(): bool
    {
        return Plugin::getInstance()->isPro() || in_array($this->kind, self::LITE_KINDS, true);
    }

    /**
     * A stable key for de-duplicating within a single batch — the same beacon fired twice by a
     * double-clicked link should not become two views.
     */
    public function dedupeKey(): string
    {
        return implode('|', [$this->kind, $this->userId, $this->itemId, $this->timestamp?->getTimestamp() ?? '']);
    }
}
