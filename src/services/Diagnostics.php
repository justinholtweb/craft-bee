<?php

namespace justinholtweb\bee\services;

use Craft;
use craft\db\Query;
use justinholtweb\bee\db\Table;
use justinholtweb\bee\errors\ApiException;
use justinholtweb\bee\helpers\Ids;
use justinholtweb\bee\Plugin;
use justinholtweb\bee\records\SyncRecord;
use yii\base\Component;

/**
 * Preflight: the screen that answers "why am I not getting recommendations?".
 *
 * Every check is written to be *actionable* — a check that reports a problem without saying what to
 * do about it just moves the confusion. Each result carries a `fix`, and the ordering is roughly
 * the order in which things have to be true.
 */
class Diagnostics extends Component
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const ERROR = 'error';
    public const INFO = 'info';

    /**
     * @return array<int, array{key: string, label: string, status: string, message: string, fix: ?string}>
     */
    public function run(bool $includeRemote = true): array
    {
        $checks = [
            $this->checkEnabled(),
            $this->checkCredentials(),
        ];

        if ($includeRemote && Plugin::getInstance()->getSettings()->isConfigured()) {
            $checks[] = $this->checkConnection();
        }

        $checks[] = $this->checkSources();
        $checks[] = $this->checkCatalog();

        if ($includeRemote && Plugin::getInstance()->getSettings()->isConfigured()) {
            $checks[] = $this->checkProperties();
            $checks[] = $this->checkRecommendations();
        }

        $checks[] = $this->checkTracking();
        $checks[] = $this->checkConsent();
        $checks[] = $this->checkSiteScoping();
        $checks[] = $this->checkCommerce();
        $checks[] = $this->checkFailures();

        return array_values(array_filter($checks));
    }

    public function worstStatus(array $checks): string
    {
        foreach ([self::ERROR, self::WARNING] as $status) {
            foreach ($checks as $check) {
                if ($check['status'] === $status) {
                    return $status;
                }
            }
        }

        return self::OK;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────

    private function checkEnabled(): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->enabled) {
            return $this->result('enabled', 'Plugin enabled', self::ERROR,
                'Bee is switched off, so nothing is being synced or tracked.',
                'Turn “Enabled” back on in Settings.');
        }

        if ($settings->dryRun) {
            return $this->result('enabled', 'Plugin enabled', self::WARNING,
                'Dry run is on: requests are built and logged, but nothing reaches Recombee.',
                'Turn off “Dry run” once the logged payloads look right.');
        }

        return $this->result('enabled', 'Plugin enabled', self::OK, 'Bee is on.');
    }

    private function checkCredentials(): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->getParsedValue('databaseId') === '' || $settings->getParsedValue('privateToken') === '') {
            return $this->result('credentials', 'Credentials', self::ERROR,
                'No Recombee database ID or private token.',
                'Add them in Settings — use $BEE_DATABASE_ID and $BEE_PRIVATE_TOKEN so they stay out of project config.');
        }

        $warnings = [];

        if (!str_starts_with($settings->databaseId, '$')) {
            $warnings[] = 'the database ID';
        }

        if (!str_starts_with($settings->privateToken, '$')) {
            $warnings[] = 'the private token';
        }

        if ($warnings !== []) {
            return $this->result('credentials', 'Credentials', self::WARNING,
                'Credentials are set, but ' . implode(' and ', $warnings) . ' are stored literally rather than as environment variables.',
                'Move them to .env and reference them as $BEE_DATABASE_ID / $BEE_PRIVATE_TOKEN, so staging and production can differ.');
        }

        return $this->result('credentials', 'Credentials', self::OK, 'Database ID and private token are set from the environment.');
    }

    private function checkConnection(): array
    {
        try {
            $ping = Plugin::getInstance()->getClient()->ping();

            return $this->result('connection', 'Connection', self::OK,
                sprintf('Reached %s in %dms.', $ping['host'], $ping['ms']));
        } catch (ApiException $e) {
            return $this->result('connection', 'Connection', self::ERROR,
                $e->getMessage(),
                $e->status === 401
                    ? 'Check the private token and that the region matches the one shown in the Recombee console — a database in the wrong region answers 401, not 404.'
                    : 'Check the connection log for the full response.');
        }
    }

    private function checkSources(): array
    {
        $sources = Plugin::getInstance()->getSources();
        $all = $sources->all();
        $enabled = $sources->enabled();

        if ($all === []) {
            return $this->result('sources', 'Catalog sources', self::ERROR,
                'No catalog sources, so nothing is being sent to Recombee.',
                'Add one under Bee → Catalog: pick an element type and the sections or product types it covers.');
        }

        if ($enabled === []) {
            return $this->result('sources', 'Catalog sources', self::WARNING,
                sprintf('%d source(s), all disabled.', count($all)),
                'Enable at least one under Bee → Catalog.');
        }

        return $this->result('sources', 'Catalog sources', self::OK,
            sprintf('%d source(s) enabled, covering %s.', count($enabled), implode(', ', array_map(
                static fn($t) => $t::displayName(),
                $sources->activeElementTypes(),
            ))));
    }

    private function checkCatalog(): array
    {
        $counts = Plugin::getInstance()->getCatalog()->statusCounts();
        $synced = $counts[SyncRecord::STATUS_SYNCED] ?? 0;
        $failed = $counts[SyncRecord::STATUS_FAILED] ?? 0;

        if ($synced === 0) {
            return $this->result('catalog', 'Catalog', self::ERROR,
                'Nothing has been synced yet.',
                'Run `php craft bee/sync/catalog` or press “Sync everything” on the Catalog screen.');
        }

        if ($failed > 0) {
            return $this->result('catalog', 'Catalog', self::WARNING,
                sprintf('%d item(s) synced, %d failed.', $synced, $failed),
                'Open Bee → Catalog and filter to Failed; the row carries the error Recombee returned.');
        }

        return $this->result('catalog', 'Catalog', self::OK, sprintf('%d item(s) synced.', $synced));
    }

    private function checkProperties(): array
    {
        $declared = Plugin::getInstance()->getSources()->declaredProperties();

        if ($declared['conflicts'] !== []) {
            return $this->result('properties', 'Item properties', self::ERROR,
                sprintf('Two sources disagree about the type of: %s.', implode(', ', array_keys($declared['conflicts']))),
                'Recombee has one property namespace per database, so a property can only have one type. Rename one of them, or make both sources agree.');
        }

        try {
            $remote = Plugin::getInstance()->getCatalog()->remoteProperties(true);
        } catch (ApiException $e) {
            return $this->result('properties', 'Item properties', self::WARNING,
                'Could not read the property list: ' . $e->getMessage(), null);
        }

        $missing = array_diff_key($declared['properties'], $remote);
        $mismatched = [];

        foreach (array_intersect_key($declared['properties'], $remote) as $name => $type) {
            if ($remote[$name] !== $type) {
                $mismatched[] = sprintf('%s (Bee: %s, Recombee: %s)', $name, $type, $remote[$name]);
            }
        }

        if ($mismatched !== []) {
            return $this->result('properties', 'Item properties', self::ERROR,
                'Type mismatch on ' . implode('; ', $mismatched) . '.',
                'Recombee cannot recast a property without dropping its values, so Bee will not do it silently. Delete the property in Recombee and re-sync, or change the mapping to match.');
        }

        if ($missing !== []) {
            return $this->result('properties', 'Item properties', self::WARNING,
                sprintf('%d property(ies) not defined in Recombee yet: %s.', count($missing), implode(', ', array_keys($missing))),
                'Run `php craft bee/sync/properties`, or press “Sync properties” on the Catalog screen.');
        }

        return $this->result('properties', 'Item properties', self::OK,
            sprintf('All %d property(ies) defined and matching.', count($declared['properties'])));
    }

    private function checkRecommendations(): array
    {
        try {
            $response = Plugin::getInstance()->getClient()->post(
                'recomms/users/bee-diagnostics/items/',
                ['count' => 1, 'cascadeCreate' => true],
                [],
                ['label' => 'diagnostics', 'retries' => 0, 'timeout' => 8000],
            );
        } catch (ApiException $e) {
            return $this->result('recommendations', 'Recommendations', self::ERROR,
                $e->getMessage(),
                'The catalog may be empty, or the database may still be building its first model.');
        }

        $count = count($response['recomms'] ?? []);

        if ($count === 0) {
            return $this->result('recommendations', 'Recommendations', self::WARNING,
                'Recombee answered, but returned nothing for a brand-new user.',
                'A fresh database needs a catalog and some interaction history before it can recommend. Sync the catalog, then run `php craft bee/interactions/backfill` if this is a Commerce store.');
        }

        return $this->result('recommendations', 'Recommendations', self::OK,
            'Recombee returned recommendations for a test user.');
    }

    private function checkTracking(): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->trackingEnabled) {
            return $this->result('tracking', 'Interaction tracking', self::WARNING,
                'Tracking is off, so Recombee has nothing to learn from.',
                'Turn on “Track interactions” in Settings. Without interactions, recommendations stay generic forever.');
        }

        if (!$settings->injectRuntime) {
            return $this->result('tracking', 'Interaction tracking', self::INFO,
                'Tracking is on, but the front-end runtime is not injected.',
                'Either turn on “Inject the front-end runtime”, or record views yourself with {{ craft.bee.trackView(entry) }}.');
        }

        return $this->result('tracking', 'Interaction tracking', self::OK,
            'Tracking is on and the runtime is injected on front-end pages.');
    }

    private function checkConsent(): ?array
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->consentMode !== $settings::CONSENT_COOKIE) {
            return null;
        }

        if (trim($settings->consentCookieName) === '') {
            return $this->result('consent', 'Consent', self::ERROR,
                'Consent gating is on but no consent cookie is named, so Bee is tracking nobody.',
                'Name the cookie your consent banner sets, or switch consent mode back to “Always track”.');
        }

        return $this->result('consent', 'Consent', self::OK,
            sprintf('Tracking waits for “%s” to be one of: %s.', $settings->consentCookieName,
                implode(', ', $settings->consentCookieValueList())));
    }

    private function checkSiteScoping(): ?array
    {
        $settings = Plugin::getInstance()->getSettings();
        $synced = count($settings->syncedSiteIds());

        if ($synced <= 1) {
            return null;
        }

        $scopedRows = (new Query())->from(Table::SYNC)->where(['like', 'itemId', '-s'])->count();
        $totalRows = (new Query())->from(Table::SYNC)->count();

        if ((int)$totalRows > 0 && (int)$scopedRows === 0) {
            return $this->result('sites', 'Multi-site', self::ERROR,
                sprintf('%d sites are being synced, but the catalog was built when only one was.', $synced),
                'Item IDs carry a site ID once more than one site is synced, so the existing items are under the old IDs. Run `php craft bee/sync/catalog --force` to rebuild them, then delete the strays with `php craft bee/sync/purge`.');
        }

        return $this->result('sites', 'Multi-site', self::OK,
            sprintf('%d sites synced, with site-scoped item IDs.', $synced));
    }

    private function checkCommerce(): ?array
    {
        if (!Plugin::commerceIsReady()) {
            return null;
        }

        $settings = Plugin::getInstance()->getSettings();
        $sources = Plugin::getInstance()->getSources();
        $hasCommerceSource = false;

        foreach ($sources->enabled() as $source) {
            if (str_contains($source->elementType, 'commerce')) {
                $hasCommerceSource = true;
                break;
            }
        }

        if (!$hasCommerceSource) {
            return $this->result('commerce', 'Commerce', self::INFO,
                'Commerce is installed, but no source syncs products or variants.',
                'Add a Product or Variant source under Bee → Catalog to recommend across the store.');
        }

        if (!$settings->trackPurchases) {
            return $this->result('commerce', 'Commerce', self::WARNING,
                'Products are synced but purchases are not tracked.',
                'Turn on “Track purchases”. Purchases are the strongest signal a store has.');
        }

        return $this->result('commerce', 'Commerce', self::OK,
            sprintf('Commerce %s, with the order funnel wired up.', Plugin::getInstance()->getCommerce()->version() ?? ''));
    }

    private function checkFailures(): array
    {
        $stats = Plugin::getInstance()->getLog()->stats(24);

        if ($stats['failures'] === 0) {
            return $this->result('log', 'Recent requests', self::OK,
                $stats['total'] > 0
                    ? sprintf('%d request(s) in the last 24 hours, none failed, averaging %dms.', $stats['total'], $stats['avgMs'])
                    : 'Nothing logged in the last 24 hours.');
        }

        return $this->result('log', 'Recent requests', self::WARNING,
            sprintf('%d of %d request(s) failed in the last 24 hours.', $stats['failures'], $stats['total']),
            'Open Bee → Log and filter to failures.');
    }

    private function result(string $key, string $label, string $status, string $message, ?string $fix = null): array
    {
        return [
            'key' => $key,
            'label' => Craft::t('bee', $label),
            'status' => $status,
            'message' => Craft::t('bee', $message),
            'fix' => $fix !== null ? Craft::t('bee', $fix) : null,
        ];
    }
}
