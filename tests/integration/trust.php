<?php
/**
 * What a non-admin, and an anonymous visitor, can and cannot do — checked over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-bee/tests/integration/trust.php
 *
 * `checks.php` runs as a console request, so it cannot see controller permission checks or the
 * public endpoint's rate limit. This signs in as a throwaway user holding every Bee permission but
 * not admin, and also calls the tracking endpoint anonymously.
 *
 * Bee is switched to dry-run with a placeholder database for the duration, so nothing reaches
 * Recombee. Settings, the user and any source are restored or removed in a shutdown handler.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\bee\models\PropertyMap;
use justinholtweb\bee\models\Source;
use justinholtweb\bee\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

$plugin = Plugin::getInstance();
$originalSettings = $plugin->getSettings()->toArray();
$run = substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'bee-' . bin2hex(random_bytes(12));
$user = null;
$sourceUid = null;

register_shutdown_function(function() use (&$user, &$sourceUid, $originalSettings) {
    // The trust source, and any "Leak" source a vulnerable build let the test user create — which
    // would otherwise claim every entry and break the next run of checks.php.
    foreach (Plugin::getInstance()->getSources()->all() as $leftover) {
        if ($leftover->uid === $sourceUid || str_starts_with($leftover->name, 'Leak ')) {
            Plugin::getInstance()->getSources()->delete($leftover->uid);
        }
    }

    if ($user !== null) {
        Craft::$app->getElements()->deleteElement($user, true);
    }

    // `saveModifiedConfigData()`, not `flush()`: a console script never reaches the end-of-request
    // hook that writes project config, so without it the restore silently never happens.
    Craft::$app->getPlugins()->savePluginSettings(Plugin::getInstance(), $originalSettings);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
});

// Persisted, because the HTTP requests below run in another process.
Craft::$app->getPlugins()->savePluginSettings($plugin, [
    'databaseId' => 'bee-trust-db',
    'privateToken' => 'not-a-real-token',
    'dryRun' => true,
    'trackingEnabled' => true,
    'trackGuests' => true,
] + $originalSettings);
Craft::$app->getProjectConfig()->saveModifiedConfigData();

// What an admin already set up.
$source = new Source([
    'name' => "Trust source $run",
    'elementType' => Entry::class,
    'properties' => [new PropertyMap(['name' => 'headline', 'type' => 'string', 'kind' => PropertyMap::KIND_ATTRIBUTE, 'value' => 'title'])],
]);
$plugin->getSources()->save($source);
$sourceUid = $source->uid;
Craft::$app->getProjectConfig()->flush();

$user = new User();
$user->username = "bee-editor-$run";
$user->email = "bee-editor-$run@example.com";
$user->newPassword = $password;
Craft::$app->getElements()->saveElement($user, false);
Craft::$app->getUsers()->activateUser($user);
Craft::$app->getUserPermissions()->saveUserPermissions($user->id, [
    'accesscp', 'accessplugin-bee', 'bee-viewcatalog', 'bee-managecatalog',
]);

$jar = new CookieJar();
$http = new Client(['base_uri' => 'http://localhost/', 'cookies' => $jar, 'http_errors' => false, 'allow_redirects' => false]);

$csrf = static function() use ($http): string {
    $info = json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true);

    return (string)($info['csrfTokenValue'] ?? '');
};

$login = $http->post('index.php?p=actions/users/login', [
    'headers' => ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'],
    'form_params' => ['loginName' => $user->username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()],
]);

if ($login->getStatusCode() !== 200) {
    echo "Could not sign in as the test user: {$login->getStatusCode()}\n";
    exit(1);
}

$post = static function(string $action, array $fields, bool $json = false) use ($http, $csrf) {
    return $http->post('index.php?p=admin/actions/' . $action, [
        'headers' => $json ? ['Accept' => 'application/json'] : [],
        'form_params' => $fields + ['CRAFT_CSRF_TOKEN' => $csrf()],
    ]);
};

$sourceNames = static fn() => array_map(static fn(Source $s) => $s->name, Plugin::getInstance()->getSources()->all());

echo "\nCatalog sources (project config, may carry Twig)\n";

check('a non-admin cannot create a source with a Twig property', function() use ($post, $run, $sourceNames) {
    Craft::$app->getProjectConfig()->reset();
    $response = $post('bee/catalog/save-source', [
        'name' => "Leak $run",
        'elementType' => Entry::class,
        'properties' => [['name' => 'leak', 'type' => 'string', 'kind' => 'twig', 'value' => '{{ craft.app.config.general.securityKey }}']],
    ]);
    Craft::$app->getProjectConfig()->reset();

    return $response->getStatusCode() === 403 && !in_array("Leak $run", $sourceNames(), true)
        ?: 'status ' . $response->getStatusCode();
});

check('a non-admin cannot delete a source', function() use ($post, $sourceUid, $run, $sourceNames) {
    $response = $post('bee/catalog/delete-source', ['uid' => $sourceUid], true);
    Craft::$app->getProjectConfig()->reset();

    return $response->getStatusCode() === 403 && in_array("Trust source $run", $sourceNames(), true)
        ?: 'status ' . $response->getStatusCode();
});

check('a non-admin cannot reorder sources', function() use ($post, $sourceUid) {
    return $post('bee/catalog/reorder-sources', ['ids' => json_encode([$sourceUid])], true)->getStatusCode() === 403;
});

check('the catalog screen still offers a manager the sync buttons, but no New source button', function() use ($http) {
    $response = $http->get('admin/bee/catalog');
    $html = (string)$response->getBody();

    return $response->getStatusCode() === 200
        && str_contains($html, 'data-bee-sync')
        && !str_contains($html, 'bee/catalog/new')
        && str_contains($html, 'Only admins can add or change sources')
        ?: 'status ' . $response->getStatusCode();
});

echo "\nPayload preview\n";

check('a preview of an entry the user cannot view reveals nothing', function() use ($post) {
    // The test user has no section permissions at all, so no entry is viewable to them.
    $entry = Entry::find()->status('live')->one();
    $data = json_decode((string)$post('bee/catalog/preview', ['elementId' => $entry->id, 'siteId' => $entry->siteId], true)->getBody(), true);

    return ($data['success'] ?? null) === false && !isset($data['payload']) ?: json_encode($data);
});

echo "\nThe public tracking endpoint\n";

// The burst at the end of this file fills the local address's rate bucket for the rest of the
// minute, which would fail the first checks of a second run started straight after. Clear it
// before and after. The key shape mirrors TrackController::rateLimit().
$clearRateBuckets = static function(): void {
    foreach (['127.0.0.1', '::1'] as $ip) {
        foreach ([0, 1] as $back) {
            Craft::$app->getCache()->delete('bee:rate:' . sha1($ip) . ':' . (intdiv(time(), 60) - $back));
        }
    }
};
$clearRateBuckets();
register_shutdown_function($clearRateBuckets);

$anon = new Client(['base_uri' => 'http://localhost/', 'http_errors' => false]);
$track = static fn(array $payload, string $agent = 'trust.php') => $anon->post('index.php?p=actions/bee/track/record', [
    'headers' => ['Accept' => 'application/json', 'User-Agent' => $agent],
    'json' => $payload,
]);

check('a purchase cannot be recorded from the browser', function() use ($track) {
    $data = json_decode((string)$track(['kind' => 'purchase', 'itemId' => 'e1'])->getBody(), true);

    return ($data['reason'] ?? null) === 'server-only' ?: json_encode($data);
});

check('the ordinary browser kinds are still accepted as far as the item check', function() use ($track) {
    $data = json_decode((string)$track(['kind' => 'detailview', 'itemId' => 'e999999999'])->getBody(), true);

    return ($data['reason'] ?? null) === 'unknown-item' ?: json_encode($data);
});

check('rotating the User-Agent does not escape the rate limit', function() use ($anon) {
    // Sent concurrently: one at a time, 130 requests take most of a minute, and the count starts
    // again when the minute does. Leave room if this minute is nearly over.
    if ((int)date('s') > 50) {
        sleep(61 - (int)date('s'));
    }

    $requests = static function() use ($anon) {
        for ($i = 0; $i < 130; $i++) {
            yield fn() => $anon->postAsync('index.php?p=actions/bee/track/record', [
                'headers' => ['Accept' => 'application/json', 'User-Agent' => "agent-$i"],
                'json' => ['kind' => 'nope'],
            ]);
        }
    };

    $limited = 0;
    (new GuzzleHttp\Pool($anon, $requests(), [
        'concurrency' => 20,
        'fulfilled' => function($response) use (&$limited) {
            if ($response->getStatusCode() === 429) {
                $limited++;
            }
        },
    ]))->promise()->wait();

    return $limited > 0 ?: 'no 429 after 130 requests with 130 different agents';
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
