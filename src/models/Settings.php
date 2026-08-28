<?php

namespace justinholtweb\bee\models;

use Craft;
use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;
use justinholtweb\bee\helpers\Props;

/**
 * Bee's plugin settings.
 *
 * Note what is *not* here: catalog sources and saved placements. Those live in project config,
 * because which entry types feed the recommender is a deployment concern that should travel with
 * a deploy, while credentials are per-environment and belong in `.env`.
 *
 * No rule on this model is `required`. A `required` credential makes a fresh install unable to save
 * *any* setting until it is filled in, because `savePluginSettings()` validates the whole model.
 */
class Settings extends Model
{
    public const REGIONS = [
        'us-west' => 'US West',
        'eu-west' => 'EU West',
        'ap-se' => 'Asia Pacific (Southeast)',
        'ca-east' => 'Canada East',
    ];

    public const SYNC_QUEUE = 'queue';
    public const SYNC_INLINE = 'inline';

    public const LOG_ALL = 'all';
    public const LOG_ERRORS = 'errors';
    public const LOG_NONE = 'none';

    public const CONSENT_ALWAYS = 'always';
    public const CONSENT_COOKIE = 'cookie';

    // ─── Connection ──────────────────────────────────────────────────────────────────────────

    /** Master switch. Off means Bee makes no outbound request at all, from anywhere. */
    public bool $enabled = true;

    /** Recombee database ID. Env-parsed. */
    public string $databaseId = '';

    /** Recombee *private* token. Env-parsed, and never rendered back into the CP or the log. */
    public string $privateToken = '';

    /** One of self::REGIONS. */
    public string $region = 'us-west';

    /** Optional full base URI override, for Recombee dedicated deployments. Env-parsed. */
    public string $baseUri = '';

    /** Request timeout in milliseconds. Recommendations use the shorter `recommendTimeout`. */
    public int $timeout = 10000;

    /** Timeout for anything rendered into a page. Kept low on purpose: see `failOpen` below. */
    public int $recommendTimeout = 3000;

    /** Retries for 5xx and connection failures. Never applied to 4xx. */
    public int $retries = 2;

    /**
     * Log every request, only failures, or nothing.
     */
    public string $logMode = self::LOG_ERRORS;

    /** Days of log to keep. 0 keeps everything. */
    public int $logRetentionDays = 30;

    /**
     * Build every request and log it, but send nothing. The way to see exactly what Bee would push
     * before pointing it at a real database.
     */
    public bool $dryRun = false;

    // ─── Catalog ─────────────────────────────────────────────────────────────────────────────

    /** Site IDs to sync, or '*' for all. */
    public string|array $syncSites = '*';

    /** Push an element to Recombee when it is saved. */
    public bool $autoSync = true;

    /** `queue` pushes on a job; `inline` pushes during the save request. */
    public string $syncMode = self::SYNC_QUEUE;

    /** Requests per batch when syncing in bulk. */
    public int $batchSize = 500;

    /** Delete the Recombee item when the Craft element is deleted. */
    public bool $deleteOnDelete = true;

    /** Create item properties in Recombee automatically when a mapping introduces a new one. */
    public bool $autoCreateProperties = true;

    // ─── Interactions ────────────────────────────────────────────────────────────────────────

    /** Master switch for interaction tracking. */
    public bool $trackingEnabled = true;

    /** Inject the front-end runtime (detail views, dwell time, click attribution). */
    public bool $injectRuntime = true;

    /** Track people who are not signed in, under a cookie-held ID. */
    public bool $trackGuests = true;

    public string $guestCookieName = 'CraftBeeId';

    public int $guestCookieDays = 365;

    /**
     * `always` tracks everyone; `cookie` tracks only when the consent cookie holds one of
     * `consentCookieValues`. Unknown consent is treated as "not granted" — the visitor has not
     * been asked yet, and a view sent now cannot be un-sent later.
     */
    public string $consentMode = self::CONSENT_ALWAYS;

    public string $consentCookieName = '';

    public string $consentCookieValues = '1,true,yes,granted';

    /** Merge a guest's history into their account when they sign in. Pro. */
    public bool $mergeGuestsOnLogin = true;

    /** Seconds a visitor must be on a page before the detail view is sent. */
    public int $detailViewDelay = 3;

    // ─── Commerce ────────────────────────────────────────────────────────────────────────────

    public bool $trackPurchases = true;

    public bool $trackCartAdditions = true;

    /** Send variants rather than products as the purchased item, when both are synced. */
    public bool $purchaseVariants = true;

    // ─── Recommendations ─────────────────────────────────────────────────────────────────────

    public int $defaultCount = 6;

    /**
     * Add a "still live" filter to every recommendation request. The catalog is push-based and can
     * lag, so without this the first visible symptom of a sync problem is a recommendation for
     * something that was unpublished.
     */
    public bool $filterLive = true;

    /** 0–1. How hard to avoid re-showing items the visitor has already seen. */
    public float $rotationRate = 0.1;

    /** Seconds before a shown item is fair game again. */
    public int $rotationTime = 7200;

    /** '', 'low', 'medium' or 'high'. */
    public string $minRelevance = '';

    /** Keep a ledger of handed-out recommendations so interactions can be attributed. Pro. */
    public bool $attribution = true;

    /** Days of attribution ledger to keep. */
    public int $attributionRetentionDays = 90;

    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function behaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => ['databaseId', 'privateToken', 'baseUri'],
            ],
        ];
    }

    protected function defineRules(): array
    {
        return [
            [['region'], 'in', 'range' => array_keys(self::REGIONS)],
            [['syncMode'], 'in', 'range' => [self::SYNC_QUEUE, self::SYNC_INLINE]],
            [['logMode'], 'in', 'range' => [self::LOG_ALL, self::LOG_ERRORS, self::LOG_NONE]],
            [['consentMode'], 'in', 'range' => [self::CONSENT_ALWAYS, self::CONSENT_COOKIE]],
            [['minRelevance'], 'in', 'range' => ['', 'low', 'medium', 'high']],
            [['timeout', 'recommendTimeout'], 'integer', 'min' => 250, 'max' => 120000],
            [['retries'], 'integer', 'min' => 0, 'max' => 5],
            [['batchSize'], 'integer', 'min' => 1, 'max' => 10000],
            [['defaultCount'], 'integer', 'min' => 1, 'max' => 1000],
            [['detailViewDelay'], 'integer', 'min' => 0, 'max' => 600],
            [['guestCookieDays'], 'integer', 'min' => 1, 'max' => 3650],
            [['logRetentionDays', 'attributionRetentionDays'], 'integer', 'min' => 0],
            [['rotationRate'], 'number', 'min' => 0, 'max' => 1],
            [['rotationTime'], 'integer', 'min' => 0],
            [['guestCookieName'], 'match', 'pattern' => '/^[A-Za-z0-9_\-]+$/',
                'message' => Craft::t('bee', 'Cookie names can only contain letters, numbers, hyphens and underscores.')],
            // Correctness only, and only when a value is present. Never `required` — see the class docblock.
            [['databaseId'], 'match', 'pattern' => '/^[A-Za-z0-9_\-]+$/', 'skipOnEmpty' => true,
                'when' => fn(self $m) => !str_starts_with($m->databaseId, '$'),
                'message' => Craft::t('bee', 'That does not look like a Recombee database ID.')],
        ];
    }

    /**
     * The site IDs Bee syncs, resolved from '*' or a stored list.
     */
    public function syncedSiteIds(): array
    {
        $all = array_map(static fn($s) => (int)$s->id, Craft::$app->getSites()->getAllSites());

        if ($this->syncSites === '*' || $this->syncSites === '' || $this->syncSites === []) {
            return $all;
        }

        $ids = array_map('intval', (array)$this->syncSites);
        $ids = array_values(array_intersect($ids, $all));

        // A stored list that no longer matches any site would silently sync nothing; fall back
        // rather than leave a customer wondering why their catalog is empty.
        return $ids ?: $all;
    }

    public function syncsSite(int $siteId): bool
    {
        return in_array($siteId, $this->syncedSiteIds(), true);
    }

    /**
     * Whether there is enough here to talk to Recombee at all.
     */
    public function isConfigured(): bool
    {
        return $this->enabled
            && $this->getParsedValue('databaseId') !== ''
            && $this->getParsedValue('privateToken') !== '';
    }

    /**
     * The value of an env-parsed setting with `$VARIABLE` resolved.
     *
     * Everything that actually talks to Recombee reads credentials through here, never off the
     * property, so a `$RECOMBEE_TOKEN` in the CP field works the same as a literal token.
     */
    public function getParsedValue(string $attribute): string
    {
        return (string)(App::parseEnv($this->$attribute) ?? '');
    }

    public function consentCookieValueList(): array
    {
        return array_values(array_filter(array_map(
            static fn($v) => strtolower(trim($v)),
            explode(',', $this->consentCookieValues),
        )));
    }

    /**
     * The property types Bee will offer in the mapping UI.
     */
    public function propertyTypes(): array
    {
        return Props::TYPES;
    }
}
