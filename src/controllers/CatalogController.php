<?php

namespace justinholtweb\bee\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\helpers\Cp;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\bee\db\Table;
use justinholtweb\bee\errors\ApiException;
use justinholtweb\bee\helpers\Ids;
use justinholtweb\bee\helpers\Props;
use justinholtweb\bee\jobs\SyncSource;
use justinholtweb\bee\models\PropertyMap;
use justinholtweb\bee\models\Source;
use justinholtweb\bee\Plugin;
use justinholtweb\bee\records\SyncRecord;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class CatalogController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // The nav item links to `bee`, which lands here. Someone who may read the log but not the
        // catalog goes there instead of meeting a 403 on their way into the section.
        $user = Craft::$app->getUser();

        if ($action->id === 'index' && !$user->checkPermission('bee-viewCatalog') && $user->checkPermission('bee-viewLog')) {
            $this->redirect(UrlHelper::cpUrl('bee/log'));

            return false;
        }

        $this->requirePermission('bee-viewCatalog');

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $counts = $plugin->getCatalog()->statusCounts();

        return $this->renderTemplate('bee/catalog/_index', [
            'plugin' => $plugin,
            'sources' => $plugin->getSources()->all(),
            'counts' => $counts,
            'total' => array_sum($counts),
            'declared' => $plugin->getSources()->declaredProperties(),
            // Syncing writes to the database and Recombee, so it works in production. Editing a
            // source writes project config, so it needs an admin and `allowAdminChanges`.
            'canManage' => Craft::$app->getUser()->checkPermission('bee-manageCatalog'),
            'canEditSources' => $this->canEditSources(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
            'recent' => $this->recentRows(),
        ]);
    }

    public function actionEditSource(?string $uid = null): Response
    {
        $plugin = Plugin::getInstance();
        $source = $uid !== null ? $plugin->getSources()->get($uid) : new Source();

        if ($source === null) {
            throw new NotFoundHttpException('No such catalog source.');
        }

        return $this->renderTemplate('bee/catalog/_source', [
            'plugin' => $plugin,
            'source' => $source,
            'isNew' => $uid === null,
            'elementTypeOptions' => $this->elementTypeOptions(),
            'scopeOptions' => $this->scopeOptions(),
            'propertyTypeOptions' => array_map(
                static fn(string $t) => ['label' => Props::typeLabel($t), 'value' => $t],
                Props::TYPES,
            ),
            'kindOptions' => [
                ['label' => Craft::t('bee', 'Custom field'), 'value' => PropertyMap::KIND_FIELD],
                ['label' => Craft::t('bee', 'Element attribute'), 'value' => PropertyMap::KIND_ATTRIBUTE],
                ['label' => Craft::t('bee', 'Built-in mapper'), 'value' => PropertyMap::KIND_SPECIAL],
                ['label' => Craft::t('bee', 'Twig'), 'value' => PropertyMap::KIND_TWIG],
            ],
            'specialOptions' => $plugin->getCatalog()->specialOptions(),
            'readOnly' => !$this->canEditSources(),
            'adminChangesOff' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }

    private function canEditSources(): bool
    {
        return (bool)Craft::$app->getUser()->getIdentity()?->admin
            && Craft::$app->getConfig()->getGeneral()->allowAdminChanges;
    }

    public function actionSaveSource(): ?Response
    {
        $this->requirePostRequest();
        // Sources live in project config, and a Twig property is code: both are admin work. The
        // default argument also refuses the save where `allowAdminChanges` is off, so production
        // cannot drift from project.yaml. `bee-manageCatalog` still runs syncs and purges.
        $this->requireAdmin();

        $request = Craft::$app->getRequest();
        $uid = $request->getBodyParam('uid');
        $plugin = Plugin::getInstance();
        $source = $uid ? $plugin->getSources()->get($uid) : new Source();

        if ($source === null) {
            throw new NotFoundHttpException('No such catalog source.');
        }

        $source->name = (string)$request->getBodyParam('name', '');
        $source->enabled = (bool)$request->getBodyParam('enabled', true);
        $source->elementType = (string)$request->getBodyParam('elementType', $source->elementType);
        // Only UIDs that belong to the chosen element type: a section UID kept on a user source would
        // match nothing, and the source would silently sync nobody.
        $source->groupUids = $this->validUids($request->getBodyParam('groupUids', []), $this->groupOptions($source->elementType));
        $source->typeUids = $this->validUids($request->getBodyParam('typeUids', []), $this->typeOptions($source->elementType));
        $source->liveOnly = (bool)$request->getBodyParam('liveOnly', true);
        $source->properties = $this->readProperties($request->getBodyParam('properties', []));

        if (!$plugin->getSources()->save($source)) {
            Craft::$app->getSession()->setError(Craft::t('bee', 'Couldn’t save the source.'));
            Craft::$app->getUrlManager()->setRouteParams(['source' => $source]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('bee', 'Source saved.'));

        return $this->redirectToPostedUrl($source, UrlHelper::cpUrl('bee/catalog'));
    }

    public function actionDeleteSource(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin();

        $uid = (string)Craft::$app->getRequest()->getRequiredBodyParam('uid');

        return $this->asJson(['success' => Plugin::getInstance()->getSources()->delete($uid)]);
    }

    public function actionReorderSources(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin();

        $uids = \craft\helpers\Json::decodeIfJson(Craft::$app->getRequest()->getRequiredBodyParam('ids'));

        return $this->asJson(['success' => Plugin::getInstance()->getSources()->reorder((array)$uids)]);
    }

    /**
     * Queue a full sync — of one source, or of everything.
     */
    public function actionSync(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('bee-manageCatalog');

        $request = Craft::$app->getRequest();
        $uid = $request->getBodyParam('uid');
        $force = (bool)$request->getBodyParam('force');
        $sources = $uid ? array_filter([Plugin::getInstance()->getSources()->get($uid)])
            : Plugin::getInstance()->getSources()->enabled();

        foreach ($sources as $source) {
            Craft::$app->getQueue()->push(new SyncSource([
                'sourceUid' => $source->uid,
                'force' => $force,
            ]));
        }

        $message = Craft::t('bee', '{n, plural, =0{Nothing to sync} =1{Queued a sync for 1 source} other{Queued syncs for # sources}}', [
            'n' => count($sources),
        ]);

        if ($request->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'message' => $message]);
        }

        Craft::$app->getSession()->setNotice($message);

        return $this->redirectToPostedUrl();
    }

    /**
     * Create every declared item property in Recombee.
     */
    public function actionSyncProperties(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('bee-manageCatalog');

        try {
            $result = Plugin::getInstance()->getCatalog()->syncProperties();
        } catch (ApiException $e) {
            return $this->asFailure($e->getMessage());
        }

        if ($result['conflicts'] !== []) {
            return $this->asFailure(Craft::t('bee', 'Type conflicts on: {names}. Recombee cannot change a property’s type without dropping its values, so nothing was changed.', [
                'names' => implode(', ', array_keys($result['conflicts'])),
            ]));
        }

        return $this->asSuccess(Craft::t('bee', '{n, plural, =0{Every property already exists} =1{Created 1 property} other{Created # properties}}', [
            'n' => count($result['created']),
        ]));
    }

    /**
     * Show exactly what Bee would send for one element.
     *
     * Goes through `Catalog::buildItem()`, the same method the sync uses, so what is shown here is
     * what Recombee receives — not an approximation of it.
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $siteId = (int)($request->getBodyParam('siteId') ?: Craft::$app->getSites()->getPrimarySite()->id);

        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);

        // Same answer for "missing" and "not yours": the preview shows every mapped value, so it
        // must not become a way to read entries in sections this user has no access to.
        if ($element === null || !Craft::$app->getElements()->canView($element)) {
            return $this->asJson(['success' => false, 'error' => Craft::t('bee', 'That element no longer exists.')]);
        }

        $source = Plugin::getInstance()->getSources()->forElement($element);

        if ($source === null) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('bee', 'No catalog source claims that element, so it is not synced.'),
            ]);
        }

        $values = Plugin::getInstance()->getCatalog()->buildItem($element, $source);

        return $this->asJson([
            'success' => true,
            'itemId' => Ids::forElement($element),
            'source' => $source->name,
            'included' => $source->includes($element),
            'payload' => $values,
            'json' => \craft\helpers\Json::encode($values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Delete the Recombee items behind rows that are no longer part of any source.
     */
    public function actionPurge(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('bee-manageCatalog');

        $rows = (new Query())
            ->select(['itemId'])
            ->from(Table::SYNC)
            ->where(['status' => [SyncRecord::STATUS_EXCLUDED, SyncRecord::STATUS_FAILED]])
            ->column();

        $catalog = Plugin::getInstance()->getCatalog();
        $deleted = 0;

        foreach (array_unique($rows) as $itemId) {
            if ($catalog->deleteItem($itemId)) {
                $deleted++;
            }
        }

        return $this->asSuccess(Craft::t('bee', '{n, plural, =0{Nothing to purge} =1{Deleted 1 item} other{Deleted # items}}', ['n' => $deleted]));
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @return PropertyMap[]
     */
    private function readProperties(mixed $posted): array
    {
        $properties = [];

        foreach ((array)$posted as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = trim((string)($row['name'] ?? ''));

            // An editable table always posts a blank final row.
            if ($name === '') {
                continue;
            }

            $properties[] = new PropertyMap([
                'name' => $name,
                'type' => (string)($row['type'] ?? Props::TYPE_STRING),
                'kind' => (string)($row['kind'] ?? PropertyMap::KIND_FIELD),
                'value' => trim((string)($row['value'] ?? '')),
                'enabled' => !isset($row['enabled']) || (bool)$row['enabled'],
            ]);
        }

        return $properties;
    }

    private function elementTypeOptions(): array
    {
        return array_map(
            static fn(string $class) => ['label' => $class::displayName(), 'value' => $class],
            Ids::supportedTypes(),
        );
    }

    /**
     * The scope choices for every element type, so the edit screen can switch between them without
     * a save.
     *
     * @return array<string, array{groups: array, types: array}>
     */
    private function scopeOptions(): array
    {
        $options = [];

        foreach (Ids::supportedTypes() as $class) {
            $options[$class] = [
                'groups' => $this->groupOptions($class),
                'types' => $this->typeOptions($class),
            ];
        }

        return $options;
    }

    /**
     * @return string[]
     */
    private function validUids(mixed $posted, array $options): array
    {
        $allowed = array_column($options, 'value');

        return array_values(array_filter(
            (array)$posted,
            static fn($uid) => is_string($uid) && in_array($uid, $allowed, true),
        ));
    }

    /**
     * Sections, volumes, category groups or product types — whatever this element type groups by.
     */
    private function groupOptions(string $elementType): array
    {
        $options = [];

        switch ($elementType) {
            case \craft\elements\Entry::class:
                foreach (Craft::$app->getEntries()->getAllSections() as $section) {
                    $options[] = ['label' => $section->name, 'value' => $section->uid];
                }
                break;
            case \craft\elements\Category::class:
                foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
                    $options[] = ['label' => $group->name, 'value' => $group->uid];
                }
                break;
            case \craft\elements\Asset::class:
                foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
                    $options[] = ['label' => $volume->name, 'value' => $volume->uid];
                }
                break;
            case \craft\elements\User::class:
                foreach (Craft::$app->getUserGroups()->getAllGroups() as $group) {
                    $options[] = ['label' => $group->name, 'value' => $group->uid];
                }
                break;
            default:
                if (Plugin::commerceIsReady() && str_contains($elementType, 'commerce')) {
                    foreach (\craft\commerce\Plugin::getInstance()->getProductTypes()->getAllProductTypes() as $type) {
                        $options[] = ['label' => $type->name, 'value' => $type->uid];
                    }
                }
        }

        return $options;
    }

    private function typeOptions(string $elementType): array
    {
        if ($elementType !== \craft\elements\Entry::class) {
            return [];
        }

        $options = [];

        foreach (Craft::$app->getEntries()->getAllEntryTypes() as $entryType) {
            $options[] = ['label' => $entryType->name, 'value' => $entryType->uid];
        }

        return $options;
    }

    private function recentRows(int $limit = 25): array
    {
        return (new Query())
            ->select(['id', 'elementId', 'siteId', 'itemId', 'elementType', 'status', 'error', 'syncedAt'])
            ->from(Table::SYNC)
            ->orderBy(['dateUpdated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->all();
    }
}
