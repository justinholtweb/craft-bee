<?php
/**
 * Bee GraphQL checks — `beeRecommend`, `beeRelated`, `beeSearch`.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-bee/tests/integration/graphql.php
 *
 * Every query goes through `Craft::$app->getGql()->executeQuery()` against an in-memory schema, so
 * schema components, the schema's read scopes, argument validation and Craft's own result cache are
 * the real ones. Recombee is a Guzzle mock, as in `checks.php`.
 *
 * The visitor is set by hand on the Identity service: a console request has no session or cookie,
 * which is exactly the "nobody to personalise for" case the suite also checks.
 *
 * Idempotent: the edition and settings are changed in memory only, and the sync and ledger rows it
 * writes are removed.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\models\GqlSchema;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as PsrResponse;
use justinholtweb\bee\db\Table;
use justinholtweb\bee\gql\queries\Bee as BeeQueries;
use justinholtweb\bee\gql\resolvers\Recommendations as RecommendationsResolver;
use justinholtweb\bee\helpers\Ids;
use justinholtweb\bee\models\Settings;
use justinholtweb\bee\Plugin;
use justinholtweb\bee\records\SyncRecord;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";

            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

/**
 * Run a closure with a mock transport in place. Returns [$result, $history].
 */
function withMock(array $responses, callable $fn): array
{
    $history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    $client = Plugin::getInstance()->getClient();
    $client->setHttpClient(new GuzzleClient(['handler' => $stack, 'http_errors' => true]));

    try {
        $result = $fn();
    } finally {
        $client->setHttpClient(null);
    }

    return [$result, $history];
}

function jsonResponse(mixed $body, int $status = 200): PsrResponse
{
    return new PsrResponse($status, ['Content-Type' => 'application/json'], Json::encode($body));
}

function requestBody(array $history, int $i): array
{
    $decoded = Json::decodeIfJson((string)$history[$i]['request']->getBody());

    return is_array($decoded) ? $decoded : [];
}

function requestUri(array $history, int $i): string
{
    return (string)$history[$i]['request']->getUri();
}

/**
 * Who Bee thinks is asking. Null is a visitor Bee may not track.
 */
function visitor(?string $userId): void
{
    $identity = Plugin::getInstance()->getIdentity();
    $reflection = new ReflectionClass($identity);
    $reflection->getProperty('resolved')->setValue($identity, $userId);
    $reflection->getProperty('resolvedIsSet')->setValue($identity, true);
}

function schema(array $scope): GqlSchema
{
    return new GqlSchema([
        'name' => 'Bee test',
        'uid' => StringHelper::UUID(),
        'scope' => $scope,
    ]);
}

/**
 * Execute a query through Craft's GraphQL service, the way the `/api` endpoint does.
 *
 * Craft builds the `Query` type once per process, from whichever schema and edition asked first, so
 * it is flushed between schemas and editions — except where a check needs Craft's result cache to
 * survive between two runs. Flushing only works with lazy types: a prebuilt schema re-registers
 * Craft's `QueryArgument` scalar and refuses the duplicate, which has nothing to do with Bee.
 */
function gql(GqlSchema $schema, string $query, array $variables = [], bool $flush = true): array
{
    $service = Craft::$app->getGql();

    if ($flush) {
        $service->flushCaches();
    }

    // Debug mode keeps the "Cannot query field" rule, which Craft drops in production — without it
    // a query for a field the schema does not have quietly answers `data: []`.
    return $service->executeQuery($schema, $query, $variables, null, true);
}

function errors(array $result): string
{
    return implode(' | ', array_column($result['errors'] ?? [], 'message'));
}

$plugin = Plugin::getInstance();
$settings = $plugin->getSettings();
$originalSettings = $settings->toArray();
$originalEdition = $plugin->edition;
$originalCaching = Craft::$app->getConfig()->getGeneral()->enableGraphqlCaching;
$originalLazy = Craft::$app->getConfig()->getGeneral()->lazyGqlTypes;
$createdSyncIds = [];
$restoredSync = [];
$ledgerUsers = ['ugql-a', 'ugql-b', 'ugql-search'];

try {
    $settings->databaseId = 'bee-test-db';
    $settings->privateToken = 'not-a-real-token';
    $settings->region = 'eu-west';
    $settings->baseUri = '';
    $settings->enabled = true;
    $settings->dryRun = false;
    $settings->logMode = Settings::LOG_ERRORS;
    $settings->trackingEnabled = true;
    $settings->consentMode = Settings::CONSENT_ALWAYS;
    $settings->retries = 0;
    $settings->attribution = true;

    $entry = Entry::find()->section('news')->status('live')->one();
    $others = Entry::find()->section('news')->status('live')->id(['not', $entry?->id])->limit(2)->all();
    $hidden = Entry::find()->section(['not', 'news'])->status('live')->one();

    if ($entry === null || count($others) < 2) {
        throw new RuntimeException('The harness needs three live news entries.');
    }

    // One site synced, so item IDs are the plain `e42` form.
    $settings->syncSites = [(int)$entry->siteId];

    $site = Craft::$app->getSites()->getSiteById((int)$entry->siteId);
    $newsUid = $entry->getSection()->uid;
    $readable = ["sites.$site->uid:read", "sections.$newsUid:read", BeeQueries::SCOPE . ':read'];
    $schema = schema($readable);

    $recommendQuery = '{ beeRecommend(count: 3, scenario: "home") { recommId scenario kind failed itemIds elements { id title } } }';

    // Every element the suite expects to come back must be in the sync table, and any row already
    // there is put back the way it was found.
    $markSynced = function(Entry $element) use (&$createdSyncIds, &$restoredSync): void {
        $record = SyncRecord::findOne(['elementId' => $element->id, 'siteId' => $element->siteId]);

        if ($record === null) {
            $record = new SyncRecord();
            $record->elementId = (int)$element->id;
            $record->siteId = (int)$element->siteId;
            $record->elementType = Entry::class;
            $record->itemId = Ids::forElement($element);
            $record->status = SyncRecord::STATUS_SYNCED;
            $record->save(false);
            $createdSyncIds[] = $record->id;

            return;
        }

        $restoredSync[$record->id] = ['itemId' => $record->itemId, 'status' => $record->status];
        $record->itemId = Ids::forElement($element);
        $record->status = SyncRecord::STATUS_SYNCED;
        $record->save(false);
    };

    // ─────────────────────────────────────────────────────────────────────────────────────────
    section('Edition and schema');

    $plugin->edition = Plugin::EDITION_PRO;

    check('the queries survive Craft’s prebuilt (non-lazy) schema validation', function() use ($schema) {
        Craft::$app->getConfig()->getGeneral()->lazyGqlTypes = false;
        visitor(null);

        [$result, $history] = withMock([], fn() => Craft::$app->getGql()->executeQuery($schema, '{ beeRecommend { failed itemIds } beeRelated(elementId: 1) { failed } beeSearch(query: "") { failed } }', null, null, true));

        return !isset($result['errors']) && ($result['data']['beeRecommend']['failed'] ?? null) === true && $history === []
            ?: Json::encode($result);
    });

    // Every check below flushes the schema between runs, which needs lazy types (see `gql()`).
    Craft::$app->getConfig()->getGeneral()->lazyGqlTypes = true;

    check('Lite registers no schema component and no queries', function() use ($plugin, $schema, $recommendQuery) {
        $plugin->edition = Plugin::EDITION_LITE;
        Craft::$app->getGql()->flushCaches();
        $components = Craft::$app->getGql()->getAllSchemaComponents()['queries'];
        $result = gql($schema, $recommendQuery);
        $plugin->edition = Plugin::EDITION_PRO;

        return !isset($components['Bee']) && str_contains(errors($result), 'beeRecommend')
            ?: 'components: ' . implode(', ', array_keys($components)) . ' / ' . errors($result);
    });

    $plugin->edition = Plugin::EDITION_PRO;

    check('Pro registers the bee.recommendations:read component', function() {
        Craft::$app->getGql()->flushCaches();
        $components = Craft::$app->getGql()->getAllSchemaComponents()['queries'];

        return isset($components['Bee'][BeeQueries::SCOPE . ':read']) ?: Json::encode(array_keys($components));
    });

    check('a schema without the component cannot see the queries', function() use ($site, $newsUid, $recommendQuery) {
        [$result, $history] = withMock([], fn() => gql(schema(["sites.$site->uid:read", "sections.$newsUid:read"]), $recommendQuery));

        return str_contains(errors($result), 'Cannot query field "beeRecommend"') && $history === []
            ?: errors($result);
    });

    // ─────────────────────────────────────────────────────────────────────────────────────────
    section('beeRecommend');

    check('returns elements in Recombee’s order with aligned item IDs and the recommId', function() use ($schema, $recommendQuery, $entry, $others) {
        visitor('ugql-a');
        $ids = [Ids::forElement($others[1]), Ids::forElement($entry), Ids::forElement($others[0])];

        [$result, $history] = withMock([
            jsonResponse(['recommId' => 'gql-r1', 'recomms' => array_map(static fn($id) => ['id' => $id], $ids)]),
        ], fn() => gql($schema, $recommendQuery));

        $data = $result['data']['beeRecommend'] ?? null;
        $elementIds = array_map(static fn($e) => 'e' . $e['id'], $data['elements'] ?? []);

        return $data !== null
            && $data['recommId'] === 'gql-r1'
            && $data['failed'] === false
            && $data['kind'] === 'user'
            && $data['scenario'] === 'home'
            && $data['itemIds'] === $ids
            && $elementIds === $ids
            && str_contains(requestUri($history, 0), '/recomms/users/ugql-a/items/')
            && requestBody($history, 0)['scenario'] === 'home'
            && requestBody($history, 0)['count'] === 3
            ?: Json::encode($result);
    });

    check('elements answer inline fragments on their concrete GraphQL type', function() use ($schema, $entry) {
        visitor('ugql-a');
        $type = $entry->getGqlTypeName();

        [$result] = withMock([
            jsonResponse(['recommId' => 'gql-frag', 'recomms' => [['id' => Ids::forElement($entry)]]]),
        ], fn() => gql($schema, '{ beeRecommend { elements { __typename ... on ' . $type . ' { id slug } } } }'));

        $element = $result['data']['beeRecommend']['elements'][0] ?? null;

        return $element !== null && $element['__typename'] === $type && $element['slug'] === $entry->slug
            ?: Json::encode($result);
    });

    check('the visitor comes from the session, and a userId argument is refused before any request', function() use ($schema) {
        visitor('ugql-a');

        [$result, $history] = withMock([], fn() => gql($schema, '{ beeRecommend(userId: "usomeone-else") { recommId } }'));

        return str_contains(errors($result), 'Unknown argument "userId"') && $history === []
            ?: errors($result);
    });

    check('a ReQL filter argument is refused, so a caller cannot enumerate the catalog', function() use ($schema) {
        [$result, $history] = withMock([], fn() => gql($schema, '{ beeRecommend(filter: "true") { recommId } }'));

        return str_contains(errors($result), 'Unknown argument "filter"') && $history === []
            ?: errors($result);
    });

    check('no visitor to personalise for answers failed: true without calling Recombee', function() use ($schema, $recommendQuery) {
        visitor(null);

        [$result, $history] = withMock([], fn() => gql($schema, $recommendQuery));
        $data = $result['data']['beeRecommend'] ?? null;

        return $data !== null && $data['failed'] === true && $data['elements'] === [] && $history === [] && !isset($result['errors'])
            ?: Json::encode($result);
    });

    check('elements in sections the schema cannot read are dropped, IDs included', function() use ($schema, $recommendQuery, $entry, $hidden) {
        if ($hidden === null) {
            return 'no live entry outside the news section in the harness';
        }

        visitor('ugql-a');
        $hiddenId = Ids::forElement($hidden);

        [$result] = withMock([
            jsonResponse(['recommId' => 'gql-r2', 'recomms' => [['id' => $hiddenId], ['id' => Ids::forElement($entry)]]]),
        ], fn() => gql($schema, $recommendQuery));

        $data = $result['data']['beeRecommend'] ?? [];

        return ($data['itemIds'] ?? null) === [Ids::forElement($entry)] && count($data['elements']) === 1
            ?: Json::encode($result);
    });

    check('the count is clamped to 1–100', function() use ($schema) {
        visitor('ugql-a');

        [, $history] = withMock([
            jsonResponse(['recommId' => 'r', 'recomms' => []]),
            jsonResponse(['recommId' => 'r', 'recomms' => []]),
        ], function() use ($schema) {
            gql($schema, '{ beeRecommend(count: 5000) { failed } }');
            gql($schema, '{ beeRecommend(count: -3) { failed } }');
        });

        return requestBody($history, 0)['count'] === 100 && requestBody($history, 1)['count'] === 1
            ?: requestBody($history, 0)['count'] . ' / ' . requestBody($history, 1)['count'];
    });

    check('a site the schema cannot read answers failed: true without calling Recombee', function() use ($newsUid, $recommendQuery) {
        visitor('ugql-a');

        [$result, $history] = withMock([], fn() => gql(schema(["sections.$newsUid:read", BeeQueries::SCOPE . ':read']), $recommendQuery));

        return ($result['data']['beeRecommend']['failed'] ?? null) === true && $history === []
            ?: Json::encode($result);
    });

    check('an unknown site handle answers failed: true', function() use ($schema) {
        visitor('ugql-a');

        [$result, $history] = withMock([], fn() => gql($schema, '{ beeRecommend(site: "no-such-site") { failed } }'));

        return ($result['data']['beeRecommend']['failed'] ?? null) === true && $history === []
            ?: Json::encode($result);
    });

    check('Craft’s GraphQL cache never serves one visitor’s recommendations to another', function() use ($schema, $recommendQuery, $entry, $others, $originalCaching) {
        Craft::$app->getConfig()->getGeneral()->enableGraphqlCaching = true;
        $service = Craft::$app->getGql();
        $service->flushCaches();

        [[$a, $b], $history] = withMock([
            jsonResponse(['recommId' => 'gql-a', 'recomms' => [['id' => Ids::forElement($entry)]]]),
            jsonResponse(['recommId' => 'gql-b', 'recomms' => [['id' => Ids::forElement($others[0])]]]),
        ], function() use ($schema, $recommendQuery) {
            visitor('ugql-a');
            $a = gql($schema, $recommendQuery, [], false);
            visitor('ugql-b');
            $b = gql($schema, $recommendQuery, [], false);

            return [$a, $b];
        });

        $restored = Craft::$app->getConfig()->getGeneral()->enableGraphqlCaching === true;
        Craft::$app->getConfig()->getGeneral()->enableGraphqlCaching = $originalCaching;

        return count($history) === 2
            && str_contains(requestUri($history, 1), '/users/ugql-b/')
            && ($a['data']['beeRecommend']['recommId'] ?? null) === 'gql-a'
            && ($b['data']['beeRecommend']['recommId'] ?? null) === 'gql-b'
            && $restored
            ?: count($history) . ' requests; ' . Json::encode([$a, $b]) . ' restored=' . var_export($restored, true);
    });

    check('the cache guard only matches documents that name a Bee query', function() {
        return RecommendationsResolver::isBeeQuery('{ recs: beeRelated(elementId: 1) { failed } }')
            && RecommendationsResolver::isBeeQuery("query Q {\n  beeSearch(query: \"x\") { failed }\n}")
            && !RecommendationsResolver::isBeeQuery('{ entries(section: "news") { title } }')
            && !RecommendationsResolver::isBeeQuery('{ beehive { id } }')
            ?: 'matcher disagreed';
    });

    check('a Recombee failure becomes failed: true, not a GraphQL error', function() use ($schema, $recommendQuery) {
        visitor('ugql-a');

        [$result] = withMock([jsonResponse(['error' => 'boom'], 500)], fn() => gql($schema, $recommendQuery));

        return ($result['data']['beeRecommend']['failed'] ?? null) === true && !isset($result['errors'])
            ?: Json::encode($result);
    });

    check('the recommendation is written to the attribution ledger under the session’s visitor', function() {
        $row = (new craft\db\Query())->from(Table::RECOMMS)->where(['recommId' => 'gql-r1'])->one();

        return $row !== null && $row['userId'] === 'ugql-a' && $row['scenario'] === 'home'
            ?: Json::encode($row);
    });

    // ─────────────────────────────────────────────────────────────────────────────────────────
    section('beeRelated');

    $markSynced($entry);

    check('related items by element ID hit the item endpoint with the visitor as target user', function() use ($schema, $entry, $others) {
        visitor('ugql-a');

        [$result, $history] = withMock([
            jsonResponse(['recommId' => 'gql-rel', 'recomms' => [['id' => Ids::forElement($others[0])]]]),
        ], fn() => gql($schema, '{ beeRelated(elementId: ' . (int)$entry->id . ', count: 4) { recommId kind itemIds } }'));

        $data = $result['data']['beeRelated'] ?? null;

        return $data !== null
            && $data['kind'] === 'item'
            && $data['itemIds'] === [Ids::forElement($others[0])]
            && str_contains(requestUri($history, 0), '/recomms/items/' . Ids::forElement($entry) . '/items/')
            && requestBody($history, 0)['targetUserId'] === 'ugql-a'
            ?: Json::encode($result);
    });

    check('related items by Recombee item ID work the same way', function() use ($schema, $entry) {
        visitor(null);

        [$result, $history] = withMock([
            jsonResponse(['recommId' => 'gql-rel2', 'recomms' => []]),
        ], fn() => gql($schema, '{ beeRelated(itemId: "' . Ids::forElement($entry) . '") { recommId failed } }'));

        return count($history) === 1
            && str_contains(requestUri($history, 0), '/recomms/items/' . Ids::forElement($entry) . '/items/')
            && requestBody($history, 0)['targetUserId'] === 'anonymous'
            && ($result['data']['beeRelated']['recommId'] ?? null) === 'gql-rel2'
            ?: Json::encode($result);
    });

    check('an element that is not in Bee’s catalog is refused, so no ghost item is created', function() use ($schema, $others) {
        visitor('ugql-a');
        // Not marked synced. Recombee's item endpoint has cascadeCreate on, so sending it would mint
        // the item in the merchant's database.
        $synced = SyncRecord::find()->where(['elementId' => $others[1]->id, 'siteId' => $others[1]->siteId, 'status' => SyncRecord::STATUS_SYNCED])->exists();

        if ($synced) {
            return 'the harness already has this entry synced; pick another fixture';
        }

        [$result, $history] = withMock([], fn() => gql($schema, '{ a: beeRelated(elementId: ' . (int)$others[1]->id . ') { failed } b: beeRelated(itemId: "e999999999") { failed } c: beeRelated(itemId: "not an id") { failed } }'));

        $data = $result['data'] ?? [];

        return $history === [] && $data['a']['failed'] === true && $data['b']['failed'] === true && $data['c']['failed'] === true
            ?: Json::encode($result);
    });

    check('an element in a section the schema cannot read is refused even when synced', function() use ($schema, $hidden, $markSynced) {
        if ($hidden === null) {
            return 'no live entry outside the news section in the harness';
        }

        $markSynced($hidden);
        visitor('ugql-a');

        [$result, $history] = withMock([], fn() => gql($schema, '{ beeRelated(elementId: ' . (int)$hidden->id . ') { failed } }'));

        return $history === [] && ($result['data']['beeRelated']['failed'] ?? null) === true
            ?: Json::encode($result);
    });

    // ─────────────────────────────────────────────────────────────────────────────────────────
    section('beeSearch');

    check('search posts the query to the visitor’s search endpoint', function() use ($schema, $entry) {
        visitor('ugql-search');

        [$result, $history] = withMock([
            jsonResponse(['recommId' => 'gql-s', 'recomms' => [['id' => Ids::forElement($entry)]]]),
        ], fn() => gql($schema, 'query Find($q: String!) { beeSearch(query: $q, count: 10) { kind recommId elements { id } } }', ['q' => 'winter boots']));

        $data = $result['data']['beeSearch'] ?? null;

        return $data !== null
            && $data['kind'] === 'search'
            && count($data['elements']) === 1
            && str_contains(requestUri($history, 0), '/search/users/ugql-search/items/')
            && requestBody($history, 0)['searchQuery'] === 'winter boots'
            && requestBody($history, 0)['count'] === 10
            ?: Json::encode($result);
    });

    check('an empty search does not spend a request', function() use ($schema) {
        [$result, $history] = withMock([], fn() => gql($schema, '{ beeSearch(query: "   ") { failed } }'));

        return $history === [] && ($result['data']['beeSearch']['failed'] ?? null) === true
            ?: Json::encode($result);
    });

    check('Lite at execution time answers failed: true even if a schema was built under Pro', function() use ($plugin, $schema) {
        Craft::$app->getGql()->flushCaches();
        Craft::$app->getGql()->getSchemaDef($schema);
        $plugin->edition = Plugin::EDITION_LITE;

        try {
            [$result, $history] = withMock([], fn() => gql($schema, '{ beeSearch(query: "boots") { failed } }', [], false));
        } finally {
            $plugin->edition = Plugin::EDITION_PRO;
        }

        return $history === [] && ($result['data']['beeSearch']['failed'] ?? null) === true
            ?: Json::encode($result);
    });

    check('every new source file parses', function() {
        foreach (['gql/queries/Bee.php', 'gql/types/RecommendationSet.php', 'gql/resolvers/Recommendations.php'] as $file) {
            exec('php -l ' . escapeshellarg(dirname(__DIR__, 2) . '/src/' . $file), $out, $code);

            if ($code !== 0) {
                return "$file: " . implode("\n", $out);
            }
        }

        return true;
    });
} finally {
    section('Cleanup');

    visitor(null);
    $identity = Plugin::getInstance()->getIdentity();
    (new ReflectionClass($identity))->getProperty('resolvedIsSet')->setValue($identity, false);

    if ($createdSyncIds !== []) {
        Craft::$app->getDb()->createCommand()->delete(Table::SYNC, ['id' => $createdSyncIds])->execute();
    }

    foreach ($restoredSync as $id => $columns) {
        Craft::$app->getDb()->createCommand()->update(Table::SYNC, $columns, ['id' => $id])->execute();
    }

    Craft::$app->getDb()->createCommand()->delete(Table::RECOMMS, ['userId' => $ledgerUsers])->execute();
    Craft::$app->getDb()->createCommand()->delete(Table::LOG, ['like', 'label', 'ugql'])->execute();

    $plugin->edition = $originalEdition;
    Craft::configure($settings, $originalSettings);
    Craft::$app->getConfig()->getGeneral()->enableGraphqlCaching = $originalCaching;
    Craft::$app->getConfig()->getGeneral()->lazyGqlTypes = $originalLazy;
    Craft::$app->getGql()->invalidateCaches();

    echo "  ✓ sync rows, ledger rows, edition and settings restored\n";

    echo "\n";
    echo $failed === 0
        ? "\033[32mAll $passed checks passed.\033[0m\n"
        : "\033[31m$failed of " . ($passed + $failed) . " checks failed.\033[0m\n";

    exit($failed === 0 ? 0 : 1);
}
