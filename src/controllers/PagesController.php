<?php

namespace vnali\migratefromwordpress\controllers;

use Craft;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\StringHelper;
use craft\models\FieldLayoutTab;
use craft\web\Controller;
use craft\web\UrlManager;

use vnali\migratefromwordpress\feed\MigrateFeed;
use vnali\migratefromwordpress\helpers\FieldHelper;
use vnali\migratefromwordpress\helpers\GeneralHelper;
use vnali\migratefromwordpress\helpers\SiteHelper;
use vnali\migratefromwordpress\items\PageItem;
use vnali\migratefromwordpress\MigrateFromWordPress as MigrateFromWordPressPlugin;
use vnali\migratefromwordpress\models\EntryModel;
use vnali\migratefromwordpress\models\FieldDefinitionModel;
use vnali\migratefromwordpress\models\SiteModel;

use yii\caching\TagDependency;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

class PagesController extends Controller
{
    /**
     * @inheritdoc
     */
    protected array|int|bool $allowAnonymous = ['values'];

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
    }

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        $response = GeneralHelper::checkRestAPI();
        if (!$response) {
            Craft::$app->getSession()->setError(Craft::t('migrate-from-wordpress', 'Check and save plugin settings first.'));
            $this->redirect('migrate-from-wordpress/settings/plugin');
            return false;
        }

        return parent::beforeAction($action);
    }

    /**
     * Prepare migrate WordPress pages to Craft elements.
     *
     * @param string|null $pageType
     * @param FieldDefinitionModel[] $fieldsModel
     * @param SiteModel[] $siteModel
     * @param EntryModel $entryModel
     * @return Response
     */
    public function actionMigrate(?string $pageType = null, array $fieldsModel = null, array $siteModel = null, EntryModel $entryModel = null): Response
    {
        $cache = Craft::$app->getCache();
        $availablePageTypes = $cache->get('migrate-from-wordpress-available-page-types');
        if (!$availablePageTypes) {
            $availablePageTypes = [];
        }
        if (!in_array($pageType, $availablePageTypes)) {
            throw new ForbiddenHttpException($pageType . ' page type is not valid!');
        }

        $request = Craft::$app->request;
        $mergeSameBlockTypes = $request->get('mergeSameBlockTypes');
        $migrateGutenbergBlocks = $request->get('migrateGutenbergBlocks');

        // If gutenberg settings is not provided in request -e.g., when clearing cache of migration page-, use last values, otherwise default is true
        if (is_null($migrateGutenbergBlocks)) {
            $gutenbergSettings = $cache->get('migrate-from-wordpress-page-gutenberg-' . $pageType);
            $migrateGutenbergBlocks = $gutenbergSettings['migrate'] ?? 'true';
            $mergeSameBlockTypes = $gutenbergSettings['merge'] ?? 'true';
        }

        $gutenbergSettings = [];
        $gutenbergSettings['migrate'] = $migrateGutenbergBlocks;
        $gutenbergSettings['merge'] = $mergeSameBlockTypes;
        $cache->set(
            'migrate-from-wordpress-page-gutenberg-' . $pageType,
            $gutenbergSettings,
            0,
            new TagDependency(['tags' => ['migrate-from-wordpress', 'migrate-from-wordpress-page-' . $pageType . '-items']])
        );

        // Check if user converted
        GeneralHelper::hookCheckUserConvert();
        //
        $pageCacheKey = 'migrate-from-wordpress-page-' . $pageType . '-items-' . $migrateGutenbergBlocks;
        $variables = [];
        $variables = $cache->getOrSet($pageCacheKey, function() use ($pageType, $variables) {
            $pageItem = new PageItem($pageType, 1, 1, '');
            $fieldDefinitions = $pageItem->getFieldDefinitions();
            $variables['pageType'] = $pageType;
            $variables['pageTypeLabel'] = 'Default';

            unset($fieldDefinitions['authorId']);
            unset($fieldDefinitions['created']);
            unset($fieldDefinitions['wordpressSiteId']);
            unset($fieldDefinitions['wordpressPostId']);
            //unset($fieldDefinitions['wordpressSourceLanguage']);
            unset($fieldDefinitions['wordpressUUID']);
            unset($fieldDefinitions['lang']);
            unset($fieldDefinitions['status']);
            unset($fieldDefinitions['title']);
            unset($fieldDefinitions['wordpressLink']);

            $variables['fieldDefinitions'] = $fieldDefinitions;

            $view = $this->getView();
            $variables = GeneralHelper::View($view, $variables);
            $variables['entrytypes'][] = ['value' => '', 'label' => Craft::t('migrate-from-wordpress', 'select one')];
            return $variables;
        }, 0, new TagDependency(['tags' => ['migrate-from-wordpress', 'migrate-from-wordpress-page-' . $pageType . '-items']]));

        $variables['wordpressLanguages'] = SiteHelper::availableWordPressLanguages();
        $variables['craftSites'] = SiteHelper::availableCraftSites();

        if (!is_array($variables['fieldDefinitions'])) {
            $this->setFailFlash(Craft::t('migrate-from-wordpress', "'" . $variables['pageTypeLabel'] . "'" . ' content type has no item to migrate'));
            $cache->set('migrate-from-wordpress-convert-status-page-' . $pageType, 'no-data', 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));
            return $this->redirect('migrate-from-wordpress');
        }

        FieldHelper::hookWordPressLabelAndInfo($variables['fieldDefinitions']);

        $variables['fields'] = $fieldsModel;
        $variables['siteModel'] = $siteModel;
        $variables['entryModel'] = $entryModel;
        $variables['sections'][] = ['value' => '', 'label' => Craft::t('migrate-from-wordpress', 'select one')];
        foreach (Craft::$app->sections->getAllSections()  as $section) {
            $sections = [];
            $sections['value'] = $section->id;
            $sections['label'] = $section->name;
            $variables['sections'][] = $sections;
        }

        return $this->renderTemplate('migrate-from-wordpress/_page', $variables);
    }

    /**
     * Prepare passed fields.
     *
     * @return Response|null
     */
    public function actionSaveFields()
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();


        $postedFields = $request->getBodyParam('fields');
        if (!$postedFields) {
            $postedFields = [];
        }
        $pageType = $request->get('pageType');

        $validate = true;
        $cache = Craft::$app->getCache();

        $siteSettings = $request->getBodyParam('sites');
        $cache->set('migrate-from-wordpress-siteSettings-' . $pageType, $siteSettings, 0, new TagDependency(['tags' => 'migrate-from-wordpress']));

        $siteModelArray = [];
        end($siteSettings);
        $endKey = key($siteSettings);
        reset($siteSettings);
        foreach ($siteSettings as $key => $siteSetting) {
            $siteModel = new SiteModel();
            $siteModel->convert = $siteSetting['convert'];
            $converts[] = $siteSetting['convert'];
            $siteModel->wordpressLanguage = $key;
            $siteModel->craftSiteId = $siteSetting['convertTo'];
            // If this is last item.
            if ($key == $endKey) {
                $siteModel->setScenario('lastLanguage');
                $siteModel->converts = $converts;
            }
            if (!$siteModel->validate()) {
                $validate = false;
            }
            $siteModelArray[$key] = $siteModel;
        }

        $sectionId = $request->getBodyParam('section');
        $entryTypeId = $request->getBodyParam('entrytype');
        $entryModel = new EntryModel();
        $entryModel->sectionId = $sectionId;
        $entryModel->entryTypeId = $entryTypeId;
        $entryModel->setScenario('convert-page');
        if (!$entryModel->validate()) {
            $validate = false;
        }

        $fieldDefinitions = $cache->get('migrate-from-wordpress-page-' . $pageType . '-fields');
        $fieldDefinitions = json_decode($fieldDefinitions, true);

        $fieldDefinitionModelArray = [];
        foreach ($postedFields as $key => $postedField) {
            // Set convert value for disabled lightswitch
            if (!isset($postedField['convert'])) {
                $convertField = 0;
            } else {
                $convertField = $postedField['convert'];
            }
            //
            $fieldDefinitionModel = new FieldDefinitionModel();
            $fieldDefinitionModel->convert = $convertField;
            $fieldDefinitionModel->convertTo = $postedField['convertTo'];
            $craftField = explode('--', $postedField['craftField']);
            $fieldDefinitionModel->craftField = $craftField[0];
            $fieldDefinitionModel->containerField = $postedField['containerField'];
            $fieldDefinitionModel->handle = $key;
            $fieldDefinitionModel->label = $fieldDefinitions[$key]['config']['label'];
            $fieldDefinitionModel->wordpressType = $fieldDefinitions[$key]['config']['type'];
            if (!$fieldDefinitionModel->validate()) {
                $validate = false;
            }
            // Add convert status to fieldDefinitions. needed for getting value
            $fieldDefinitions[$key]['convert'] = $convertField;
            //
            // Add target craft type to fieldDefinitions. needed for getting value
            $fieldDefinitions[$key]['convertTarget'] = $postedField['convertTo'];
            //
            $fieldDefinitions[$key]['containerField'] = $postedField['containerField'];
            if (isset($craftField[1])) {
                $fieldDefinitions[$key]['volumeTarget'] = $craftField[1];
            }
            $fieldDefinitionModelArray[$key] = $fieldDefinitionModel;
        }
        $cache->set('migrate-from-wordpress-page-' . $pageType . '-fields', json_encode($fieldDefinitions), 0, new TagDependency(['tags' => 'migrate-from-wordpress']));
        $fields = $postedFields;

        if (!$validate) {
            Craft::info('Page item not saved due to validation error.', __METHOD__);
            $this->setFailFlash(Craft::t('migrate-from-wordpress', 'There was some validation error.'));
            /** @var UrlManager $urlManager */
            $urlManager = Craft::$app->getUrlManager();
            $urlManager->setRouteParams([
                'fieldsModel' => $fieldDefinitionModelArray,
                'siteModel' => $siteModelArray,
                'entryModel' => $entryModel,
            ]);
            return null;
        }

        // Get exact name of column uuid
        $uuidField = Craft::$app->fields->getFieldByHandle('wordpressUUID');
        if (!$uuidField) {
            $newField = new \craft\fields\PlainText([
                "groupId" => 1,
                "name" => 'wordpressUUID',
                "handle" => 'wordpressUUID',
            ]);
            Craft::$app->fields->saveField($newField);
            $uuidField = Craft::$app->fields->getFieldByHandle('wordpressUUID');
        }
        //

        $fieldMappings = [];
        $fieldMappingsExtra = [];

        $fields['wordpressUUID']['type'] = 'text';
        $fields['wordpressUUID']['convertTo'] = 'craft\fields\PlainText';
        $fields['wordpressUUID']['convert'] = 1;
        $fields['wordpressUUID']['craftField'] = 'wordpressUUID';

        $fields['wordpressSiteId']['type'] = 'text';
        $fields['wordpressSiteId']['convertTo'] = 'craft\fields\PlainText';
        $fields['wordpressSiteId']['convert'] = 1;
        $fields['wordpressSiteId']['craftField'] = 'wordpressSiteId';

        $fieldMappings['title']['attribute'] = true;
        $fieldMappings['title']['node'] = 'title/value';
        $fieldMappings['title']['default'] = '';

        $fieldMappings['slug']['attribute'] = true;
        $fieldMappings['slug']['node'] = 'title/value';
        $fieldMappings['slug']['default'] = '';

        $fieldMappings['postDate']['attribute'] = true;
        $fieldMappings['postDate']['node'] = 'created/value';
        $fieldMappings['postDate']['default'] = '';
        $fieldMappings['postDate']['options']['match'] = "seconds";

        $fieldMappings['parent']['attribute'] = true;
        $fieldMappings['parent']['node'] = 'parent/value';
        $fieldMappings['parent']['default'] = '';

        $fieldMappings['enabled']['attribute'] = true;
        $fieldMappings['enabled']['node'] = 'status/value';
        $fieldMappings['enabled']['default'] = '';

        $fieldMappingsExtra['title']['attribute'] = true;
        $fieldMappingsExtra['title']['node'] = 'title/value';
        $fieldMappingsExtra['title']['default'] = '';

        $fields['wordpressLink']['type'] = 'text';
        $fields['wordpressLink']['convertTo'] = 'craft\fields\PlainText';
        $fields['wordpressLink']['convert'] = 1;
        $fields['wordpressLink']['craftField'] = 'wordpressLink';

        /*
        $fieldMappingsExtra['wordpressPostId']['field'] = 'craft\fields\PlainText';
        $fieldMappingsExtra['wordpressPostId']['default'] = '';
        $fieldMappingsExtra['wordpressPostId']['node'] = 'nodeId/value';
        */

        $fieldMappings['authorId']['attribute'] = true;
        $fieldMappings['authorId']['node'] = 'authorId/value';
        $fieldMappings['authorId']['default'] = '';

        $fields['wordpressPostId']['type'] = 'text';
        $fields['wordpressPostId']['convertTo'] = 'craft\fields\PlainText';
        $fields['wordpressPostId']['convert'] = 1;
        $fields['wordpressPostId']['craftField'] = 'wordpressPostId';

        /*
        $fields['wordpressSourceLanguage']['type'] = 'text';
        $fields['wordpressSourceLanguage']['convertTo'] = 'craft\fields\PlainText';
        $fields['wordpressSourceLanguage']['convert'] = 1;
        $fields['wordpressSourceLanguage']['craftField'] = 'wordpressSourceLanguage';
        $fields['wordpressSourceLanguage']['translatable'] = 'site';
        */

        $entryType = Craft::$app->sections->getEntryTypeById($entryTypeId);
        $fieldLayout = $entryType->getFieldLayout();
        $tabs = $fieldLayout->getTabs();
        $fieldItems = [];
        if (count($tabs) == 0) {
            $tab = new FieldLayoutTab([
                'name' => 'tab1',
                'layoutId' => $fieldLayout->id,
                'sortOrder' => 99,
            ]);
            $tabs = [];
            $tabs[] = $tab;
        }

        foreach ($fields as $key => $fieldSettings) {
            $fieldItem = null;
            if ($key != 'title' && $key != 'termParent' && isset($fieldSettings['convertTo'])) {
                if (isset($fieldSettings['convert']) && $fieldSettings['convert']) {
                    FieldHelper::createFields($key, $fieldSettings, $fieldMappings, $fieldItem, $fieldMappingsExtra, 'page', $fieldDefinitions, $pageType);
                    if (!$fieldItem) {
                        throw new ServerErrorHttpException('page field item is empty for ' . $key);
                    }
                    $fieldItems[$key] = $fieldItem;
                }
            }
        }

        $parsedFields = [];
        $layoutModel = [];
        $layoutModel['tabs'] = [];
        $newTab = [];
        $newTabSuffix = StringHelper::randomString(5);
        $newTab['name'] = 'new tab' . $newTabSuffix;
        $newTab['uid'] = StringHelper::UUID();

        foreach ($tabs as $tab) {
            if ($tab->elements) {
                $layoutModel['tabs'][] = $tab;
            }
        }

        if ($fieldItems) {
            foreach ($fieldItems as $key => $fieldItem) {
                $isInTab = false;
                foreach ($tabs as $tab) {
                    foreach ($tab->elements as $element) {
                        if ($element instanceof CustomField) {
                            if ($element->attribute() == $fieldItem->handle) {
                                $isInTab = true;
                                break 2;
                            }
                        }
                    }
                }

                if (!$isInTab && !in_array($fieldItem->id, $parsedFields)) {
                    $parsedFields[] = $fieldItem->id;
                    $element = [
                        'type' => CustomField::class,
                        'fieldUid' => $fieldItem->uid,
                        'required' => false,
                    ];
                    $newTab['elements'][] = $element;
                }
            }
        }
        //array_unshift($layoutModel['tabs'], $newTab);
        $layoutModel['tabs'][] = $newTab;
        $layoutModel['uid'] = $fieldLayout->uid;
        $layoutModel['id'] = $fieldLayout->id;

        $fieldLayout = Craft::$app->getFields()->createLayout($layoutModel);
        $fieldLayout->type = Entry::class;
        $entryType->setFieldLayout($fieldLayout);

        if (!Craft::$app->getSections()->saveEntryType($entryType)) {
            throw new ServerErrorHttpException('Entry type cannot be saved.');
        }

        $migrateFeed = new MigrateFeed();
        $migrateFeed->fieldMappings = $fieldMappings;
        $migrateFeed->fieldMappingsExtra = $fieldMappingsExtra;
        $migrateFeed->typeId = $pageType;
        $migrateFeed->siteSettings = $siteSettings;
        $migrateFeed->itemType = 'page';
        $migrateFeed->sectionId = $sectionId;
        $migrateFeed->entryTypeId = $entryTypeId;
        $migrateFeed->createFeed();

        $cache->set('migrate-from-wordpress-page-siteSettings-' . $pageType, $siteSettings, 0, new TagDependency(['tags' => 'migrate-from-wordpress']));

        foreach ($siteSettings as $key => $siteSetting) {
            if ($siteSetting['convert'] == '1') {
                $feedStatus = 'feed';
            } else {
                $feedStatus = 'no feed';
            }
            Craft::$app->getCache()->set('migrate-from-wordpress-convert-status-page-' . $key . '-' . $pageType, $feedStatus, 0, new TagDependency(['tags' => 'migrate-from-wordpress']));
        }

        Craft::$app->getSession()->setNotice(Craft::t('migrate-from-wordpress', 'page feeds created.'));
        return $this->redirectToPostedUrl();
    }

    /**
     * Get values of WordPress pages.
     *
     * @param string $pageType
     * @param string $contentLanguage
     * @param string $token
     * @param int $page
     * @param int $limit
     * @param int $isUpdateFeed
     * @param int $hasUpdateFeed
     * @return Response
     */
    public function actionValues(string $pageType, string $contentLanguage = null, string $token = null, int $page = 1, int $limit = 10, $isUpdateFeed = 0, $hasUpdateFeed = 0): Response
    {
        // Prevent other feeds from running when token is regenerating
        if (Craft::$app->cache->get('migrate-from-wordpress-token-regenerate') == 'wait') {
            throw new ServerErrorHttpException('feed url is regenerating. try again');
        }

        $secretToken = Craft::$app->cache->get('migrate-from-wordpress-protect-feed-values');

        if (is_null($token)) {
            throw new ForbiddenHttpException('Token is not available');
        }
        if (!$secretToken) {
            throw new ForbiddenHttpException('Secret token is not available');
        }
        if ($secretToken != $token) {
            throw new ForbiddenHttpException('Token is not valid');
        }

        $newToken = GeneralHelper::regenerateFeedToken();

        $gutenberg = Craft::$app->getCache()->get('migrate-from-wordpress-page-gutenberg-' . $pageType);

        $cacheKey = 'migrate-from-wordpress-page-values-' . $pageType . '-' . $contentLanguage . '-' . $page . '-' . $limit . '-' . $gutenberg['migrate'];
        $cacheFeedValuesSeconds = MigrateFromWordPressPlugin::$plugin->settings->cacheFeedValuesSeconds;
        if (!is_integer($cacheFeedValuesSeconds)) {
            throw new ServerErrorHttpException('cacheFeedValuesSeconds should be integer.');
        }
        list($data, $next) = Craft::$app->getCache()->getOrSet($cacheKey, function() use ($pageType, $contentLanguage, $newToken, $page, $limit, $isUpdateFeed, $hasUpdateFeed) {
            $pageItem = new PageItem($pageType, $page, $limit, $contentLanguage);
            list($contents, $hasNext) = $pageItem->getValues();

            $fieldDefinitions = Craft::$app->getCache()->get('migrate-from-wordpress-page-' . $pageType . '-fields');
            $fieldDefinitions = json_decode($fieldDefinitions, true);

            $level = '';
            $data = [];
            foreach ($contents as $content) {
                $fieldValues = [];
                FieldHelper::analyzeFieldValues($content, $fieldValues, $level, $contentLanguage, 'page', $fieldDefinitions, $pageType);
                if ($fieldValues) {
                    $data[] = $fieldValues;
                }
            }

            if (count($data) == 0) {
                $data = null;
            }

            $page = $page + 1;
            if ($hasNext) {
                $next = Craft::getAlias('@web') . "/migrate-from-wordpress/pages/values?token=$newToken&pageType=$pageType"
                    . "&contentLanguage=" . $contentLanguage . "&page=" . $page . "&limit=" . $limit . "&isUpdateFeed=$isUpdateFeed&hasUpdateFeed=$hasUpdateFeed";
            } else {
                $next = "";
            }

            return array($data, $next);
        }, $cacheFeedValuesSeconds, new TagDependency(['tags' => 'migrate-from-wordpress']));


        return $this->asJson([
            'data' => $data,
            'next' => $next,
        ]);
    }
}
