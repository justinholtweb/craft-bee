<?php

namespace justinholtweb\bee;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\User as UserElement;
use craft\events\ElementEvent;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\services\Elements;
use craft\services\ProjectConfig;
use craft\services\UserPermissions;
use craft\web\Application as WebApplication;
use craft\web\twig\variables\Cp;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\bee\jobs\SyncElements;
use justinholtweb\bee\models\Settings;
use justinholtweb\bee\services\Catalog;
use justinholtweb\bee\services\Client;
use justinholtweb\bee\services\Commerce;
use justinholtweb\bee\services\Diagnostics;
use justinholtweb\bee\services\Identity;
use justinholtweb\bee\services\Interactions;
use justinholtweb\bee\services\Log;
use justinholtweb\bee\services\Recommendations;
use justinholtweb\bee\services\Sources;
use justinholtweb\bee\web\assets\runtime\RuntimeAsset;
use yii\base\Application;
use yii\base\Event;

/**
 * Bee — Recombee recommendations for Craft CMS and Craft Commerce.
 *
 * @property-read Client $client
 * @property-read Catalog $catalog
 * @property-read Sources $sources
 * @property-read Interactions $interactions
 * @property-read Identity $identity
 * @property-read Recommendations $recommendations
 * @property-read Commerce $commerce
 * @property-read Diagnostics $diagnostics
 * @property-read Log $log
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const HANDLE = 'bee';

    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public string $schemaVersion = '5.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    /**
     * Elements saved this request, waiting to be pushed.
     *
     * Collected and flushed once at the end of the request rather than queued per save. A bulk
     * resave of ten thousand entries would otherwise create ten thousand jobs — and every propagated
     * save, every structure move and every `resave` pass would create more.
     *
     * @var array<string, array<string, array{0: int, 1: int}>>
     */
    private array $pending = [];

    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'client' => ['class' => Client::class],
                'catalog' => ['class' => Catalog::class],
                'sources' => ['class' => Sources::class],
                'interactions' => ['class' => Interactions::class],
                'identity' => ['class' => Identity::class],
                'recommendations' => ['class' => Recommendations::class],
                'commerce' => ['class' => Commerce::class],
                'diagnostics' => ['class' => Diagnostics::class],
                'log' => ['class' => Log::class],
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->_registerTwigVariable();
        $this->_registerPermissions();
        $this->_registerCpRoutes();
        $this->_registerSiteRoutes();
        $this->_registerProjectConfigHandlers();

        // Everything below talks to Recombee. None of it should be wired up on an install that has
        // not been connected yet — an unconfigured plugin should cost nothing.
        Craft::$app->onInit(function(): void {
            if (!$this->getSettings()->isConfigured()) {
                return;
            }

            $this->_registerElementHandlers();
            $this->_registerUserHandlers();
            $this->_registerRuntime();
            $this->_registerGarbageCollection();

            if (self::commerceIsReady()) {
                $this->getCommerce()->attachEventHandlers();
            }
        });
    }

    /**
     * Whether Commerce is installed, enabled and loadable.
     *
     * Asked before touching anything Commerce, because the plugin can be installed while Commerce
     * is disabled, missing, or mid-upgrade.
     */
    public static function commerceIsReady(): bool
    {
        return class_exists(\craft\commerce\Plugin::class)
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO);
    }

    // ─── Services ────────────────────────────────────────────────────────────────────────────

    public function getClient(): Client
    {
        return $this->get('client');
    }

    public function getCatalog(): Catalog
    {
        return $this->get('catalog');
    }

    public function getSources(): Sources
    {
        return $this->get('sources');
    }

    public function getInteractions(): Interactions
    {
        return $this->get('interactions');
    }

    public function getIdentity(): Identity
    {
        return $this->get('identity');
    }

    public function getRecommendations(): Recommendations
    {
        return $this->get('recommendations');
    }

    public function getCommerce(): Commerce
    {
        return $this->get('commerce');
    }

    public function getDiagnostics(): Diagnostics
    {
        return $this->get('diagnostics');
    }

    public function getLog(): Log
    {
        return $this->get('log');
    }

    // ─── CP ──────────────────────────────────────────────────────────────────────────────────

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        if ($item === null) {
            return null;
        }

        $user = Craft::$app->getUser();
        $subnav = [];

        if ($user->checkPermission('bee-viewCatalog')) {
            $subnav['catalog'] = ['label' => Craft::t('bee', 'Catalog'), 'url' => 'bee/catalog'];
        }

        if ($user->checkPermission('bee-viewLog')) {
            $subnav['log'] = ['label' => Craft::t('bee', 'Log'), 'url' => 'bee/log'];
        }

        if ($user->checkPermission('bee-viewCatalog')) {
            $subnav['diagnostics'] = ['label' => Craft::t('bee', 'Diagnostics'), 'url' => 'bee/diagnostics'];
        }

        if ($subnav === []) {
            return null;
        }

        $item['subnav'] = $subnav;
        $item['url'] = 'bee/' . array_key_first($subnav);

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('bee/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
            'sites' => Craft::$app->getSites()->getAllSites(),
            'regions' => Settings::REGIONS,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────

    private function _registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event): void {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('bee', \justinholtweb\bee\twig\BeeVariable::class);
            },
        );
    }

    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => Craft::t('bee', 'Bee'),
                    'permissions' => [
                        'bee-viewCatalog' => [
                            'label' => Craft::t('bee', 'View the catalog and diagnostics'),
                            'nested' => [
                                'bee-manageCatalog' => ['label' => Craft::t('bee', 'Edit sources and run syncs')],
                            ],
                        ],
                        'bee-viewLog' => ['label' => Craft::t('bee', 'View the connection log')],
                    ],
                ];
            },
        );
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event): void {
                $event->rules['bee'] = 'bee/catalog/index';
                $event->rules['bee/catalog'] = 'bee/catalog/index';
                $event->rules['bee/catalog/new'] = 'bee/catalog/edit-source';
                $event->rules['bee/catalog/<uid:[\w\-]+>'] = 'bee/catalog/edit-source';
                $event->rules['bee/log'] = 'bee/log/index';
                $event->rules['bee/log/<id:\d+>'] = 'bee/log/detail';
                $event->rules['bee/diagnostics'] = 'bee/diagnostics/index';
            },
        );
    }

    /**
     * The front-end endpoints the runtime posts to.
     *
     * Named routes rather than raw action URLs so the paths can be adjusted by config, and so an
     * ad blocker matching on `/actions/bee/…` is easy to work around.
     */
    private function _registerSiteRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function(RegisterUrlRulesEvent $event): void {
                $event->rules['bee/track'] = 'bee/track/record';
                $event->rules['bee/recommend'] = 'bee/track/recommend';
            },
        );
    }

    private function _registerProjectConfigHandlers(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $invalidate = fn() => $this->getSources()->invalidate();

        $projectConfig
            ->onAdd(Sources::CONFIG_KEY . '.{uid}', $invalidate)
            ->onUpdate(Sources::CONFIG_KEY . '.{uid}', $invalidate)
            ->onRemove(Sources::CONFIG_KEY . '.{uid}', $invalidate);

        Event::on(
            ProjectConfig::class,
            ProjectConfig::EVENT_REBUILD,
            static function(\craft\events\RebuildConfigEvent $event): void {
                // Sources live only in project config, so a rebuild has nothing to read them back
                // from — leaving them alone is what stops `project-config/rebuild` erasing them.
                $existing = Craft::$app->getProjectConfig()->get(Sources::CONFIG_KEY, true);

                if ($existing !== null) {
                    $event->config['bee']['sources'] = $existing;
                }
            },
        );
    }

    /**
     * Catalog freshness.
     *
     * Typed `ElementEvent`, because that is what Craft passes. A handler typed `ModelEvent` here
     * would fatal on *every element save in the install*, including saves that have nothing to do
     * with this plugin.
     */
    private function _registerElementHandlers(): void
    {
        if (!$this->getSettings()->autoSync) {
            return;
        }

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            function(ElementEvent $event): void {
                $this->_queueSync($event->element);
            },
        );

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_RESTORE_ELEMENT,
            function(ElementEvent $event): void {
                $this->_queueSync($event->element);
            },
        );

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_DELETE_ELEMENT,
            function(ElementEvent $event): void {
                if (!$this->getSettings()->deleteOnDelete) {
                    return;
                }

                $element = $event->element;

                if ($element->getIsDraft() || $element->getIsRevision() || $element->id === null) {
                    return;
                }

                // Done inline, not queued: the sync row is what knows the Recombee item ID, and
                // Craft's foreign key takes that row away as soon as this handler returns.
                try {
                    $this->getCatalog()->forgetElement((int)$element->id);
                } catch (\Throwable $e) {
                    Craft::warning('Bee could not remove a deleted element from Recombee: ' . $e->getMessage(), 'bee');
                }
            },
        );

        Event::on(
            Application::class,
            Application::EVENT_AFTER_REQUEST,
            function(): void {
                $this->_flushPending();
            },
        );
    }

    private function _queueSync(Element|\craft\base\ElementInterface $element): void
    {
        if ($element->getIsDraft() || $element->getIsRevision() || $element->id === null || $element->siteId === null) {
            return;
        }

        if (!$this->getSettings()->syncsSite((int)$element->siteId)) {
            return;
        }

        // Ask the sources, not the element: an element nothing claims should not cost a queue job.
        if ($this->getSources()->forElement($element) === null) {
            // Except when it *used* to be claimed — that transition is exactly how an item gets
            // removed from Recombee, and only the sync table knows it happened.
            if (!$this->_hasSyncRow((int)$element->id, (int)$element->siteId)) {
                return;
            }
        }

        $key = $element->id . ':' . $element->siteId;
        $this->pending[get_class($element)][$key] = [(int)$element->id, (int)$element->siteId];
    }

    private function _hasSyncRow(int $elementId, int $siteId): bool
    {
        return (new \craft\db\Query())
            ->from(\justinholtweb\bee\db\Table::SYNC)
            ->where(['elementId' => $elementId, 'siteId' => $siteId])
            ->exists();
    }

    private function _flushPending(): void
    {
        if ($this->pending === []) {
            return;
        }

        $pending = $this->pending;
        $this->pending = [];
        $inline = $this->getSettings()->syncMode === Settings::SYNC_INLINE;

        foreach ($pending as $elementType => $elements) {
            $job = new SyncElements([
                'elementType' => $elementType,
                'elements' => array_values($elements),
            ]);

            try {
                if ($inline) {
                    $job->execute(Craft::$app->getQueue());
                } else {
                    Craft::$app->getQueue()->push($job);
                }
            } catch (\Throwable $e) {
                // A save has already happened by the time this runs. Failing here would turn a
                // recommender problem into a lost edit.
                Craft::warning('Bee could not queue a catalog sync: ' . $e->getMessage(), 'bee');
            }
        }
    }

    private function _registerUserHandlers(): void
    {
        Event::on(
            \craft\web\User::class,
            \craft\web\User::EVENT_AFTER_LOGIN,
            function(\yii\web\UserEvent $event): void {
                $identity = $event->identity;

                if ($identity instanceof UserElement) {
                    $this->getIdentity()->handleLogin($identity);
                }
            },
        );
    }

    /**
     * Inject the front-end runtime.
     *
     * The config written into the page is *page*-specific, never *visitor*-specific: no CSRF token,
     * no user ID, no consent answer. That is deliberate — anything that varies per visitor would
     * make every page uncacheable, and Bee should be invisible to a static cache.
     */
    private function _registerRuntime(): void
    {
        $settings = $this->getSettings();

        if (!$settings->injectRuntime || !$settings->trackingEnabled) {
            return;
        }

        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
            function(\craft\events\TemplateEvent $event): void {
                $request = Craft::$app->getRequest();

                if (!$request->getIsSiteRequest() || $request->getIsAjax()) {
                    return;
                }

                try {
                    $view = Craft::$app->getView();
                    $view->registerAssetBundle(RuntimeAsset::class);
                    $view->registerJs(
                        sprintf('window.Bee && window.Bee.start(%s);', \craft\helpers\Json::encode($this->runtimeConfig())),
                        View::POS_END,
                    );
                } catch (\Throwable $e) {
                    Craft::warning('Bee could not inject its runtime: ' . $e->getMessage(), 'bee');
                }
            },
        );
    }

    /**
     * The configuration written into a rendered page for the front-end runtime.
     *
     * Everything here is *page*-specific and nothing is *visitor*-specific — no CSRF token, no user
     * ID, no consent answer — which is what keeps a page carrying Bee's runtime cacheable.
     */
    public function runtimeConfig(): array
    {
        return [
            'endpoint' => UrlHelper::siteUrl('bee/track'),
            'delay' => $this->getSettings()->detailViewDelay,
        ];
    }

    private function _registerGarbageCollection(): void
    {
        Event::on(
            \craft\services\Gc::class,
            \craft\services\Gc::EVENT_RUN,
            function(): void {
                $this->getLog()->prune();
                $this->getRecommendations()->pruneLedger();
            },
        );
    }
}
