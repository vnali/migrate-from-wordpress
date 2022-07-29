<?php

namespace vnali\migratefromwordpress\controllers;

use Craft;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\ArrayHelper;
use craft\helpers\StringHelper;
use craft\models\FieldLayoutTab;
use craft\web\Controller;
use craft\web\UrlManager;

use verbb\navigation\elements\Node as NodeElement;
use verbb\navigation\Navigation as NavigationPlugin;

use vnali\migratefromwordpress\feed\MigrateFeed;
use vnali\migratefromwordpress\helpers\FieldHelper;
use vnali\migratefromwordpress\helpers\GeneralHelper;
use vnali\migratefromwordpress\helpers\SiteHelper;
use vnali\migratefromwordpress\items\MenuItem;
use vnali\migratefromwordpress\MigrateFromWordPress as MigrateFromWordPressPlugin;
use vnali\migratefromwordpress\models\EntryModel;
use vnali\migratefromwordpress\models\FieldDefinitionModel;
use vnali\migratefromwordpress\models\MenuModel;
use vnali\migratefromwordpress\models\SiteModel;

use yii\caching\TagDependency;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

class MenusController extends Controller
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
     * Prepare migrate WordPress menus to Craft elements.
     *
     * @param string $menuId
     * @param FieldDefinitionModel[] $fieldsModel
     * @param SiteModel[] $siteModel
     * @param EntryModel $entryModel
     * @param MenuModel $menuModel
     * @return Response
     */
    public function actionMigrate(string $menuId = null, array $fieldsModel = null, array $siteModel = null, EntryModel $entryModel = null, MenuModel $menuModel = null): Response
    {
        $cache = Craft::$app->getCache();
        $availableMenuTypes = $cache->get('migrate-from-wordpress-available-menu-types');
        if (!$availableMenuTypes) {
            $availableMenuTypes = [];
        }
        if (!in_array($menuId, ArrayHelper::getColumn($availableMenuTypes, 'value'))) {
            throw new ForbiddenHttpException($menuId . ' is not valid menu!');
        }
        // Check if user converted
        GeneralHelper::hookCheckUserConvert();
        //
        $menuCacheKy = 'migrate-from-wordpress-menu-' . $menuId . '-items';
        $variables = [];
        $variables = Craft::$app->getCache()->getOrSet($menuCacheKy, function() use ($menuId, $availableMenuTypes) {
            $variables['entrytypes'][] = ['value' => '', 'label' => Craft::t('migrate-from-wordpress', 'select one')];

            $menuItem = new menuItem($menuId, 1, 1);
            $fieldDefinitions = $menuItem->getFieldDefinitions();

            $variables['menuId'] = $menuId;
            $variables['menuLabel'] = $availableMenuTypes[$menuId]['label'];

            $variables['convertMenuTo'] = [
                ['value' => '', 'label' => Craft::t('migrate-from-wordpress', 'select one')],
                ['value' => 'entry', 'label' => Craft::t('migrate-from-wordpress', 'Entry')],
                ['value' => 'navigation', 'label' => Craft::t('migrate-from-wordpress', 'Navigation plugin')],
            ];

            unset($fieldDefinitions['wordpressMenuId']);
            unset($fieldDefinitions['wordpressUUID']);
            unset($fieldDefinitions['lang']);
            unset($fieldDefinitions['title']);
            unset($fieldDefinitions['wordpressLink']);
            $variables['fieldDefinitions'] = $fieldDefinitions;
            $view = $this->getView();
            $variables = GeneralHelper::View($view, $variables);

            return $variables;
        }, 0, new TagDependency(['tags' => 'migrate-from-wordpress']));

        $variables['wordpressLanguages'] = SiteHelper::availableWordPressLanguages();
        $variables['craftSites'] = SiteHelper::availableCraftSites();

        if (!is_array($variables['fieldDefinitions'])) {
            $this->setFailFlash(Craft::t('migrate-from-wordpress', "'" . $variables['menuLabel'] . "'" . ' menu has no item to migrate'));
            $cache->set('migrate-from-wordpress-convert-status-menu-' . $menuId, 'no-data', 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));
            return $this->redirect('migrate-from-wordpress');
        }

        FieldHelper::hookWordPressLabelAndInfo($variables['fieldDefinitions']);

        $variables['fields'] = $fieldsModel;
        $variables['siteModel'] = $siteModel;
        $variables['entryModel'] = $entryModel;
        $variables['menuModel'] = $menuModel;
        $variables['sections'][] = ['value' => '', 'label' => Craft::t('migrate-from-wordpress', 'select one')];
        foreach (Craft::$app->sections->getSectionsByType('structure')  as $section) {
            $sections = [];
            $sections['value'] = $section->id;
            $sections['label'] = $section->name;
            $variables['sections'][] = $sections;
        }

        $variables['navs'][] = ['value' => '', 'label' => Craft::t('migrate-from-wordpress', 'select one')];
        if (Craft::$app->plugins->isPluginEnabled('navigation')) {
            foreach (NavigationPlugin::getInstance()->navs->getAllNavs() as $nav) {
                $navs = [];
                $navs['value'] = $nav->id;
                $navs['label'] = $nav->name;
                $variables['navs'][] = $navs;
            }
        }

        return $this->renderTemplate('migrate-from-wordpress/_menus', $variables);
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

        $validate = true;
        $cache = Craft::$app->getCache();

        $siteSettings = $request->getBodyParam('sites');
        Craft::$app->cache->set('siteSettings', $siteSettings);

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
            if ($key == $endKey) { // -- this is the last item
                $siteModel->setScenario('lastLanguage');
                $siteModel->converts = $converts;
            }
            if (!$siteModel->validate()) {
                $validate = false;
            }
            $siteModelArray[$key] = $siteModel;
        }

        $menuId = $request->get('menuId');
        $convertMenuTo = $request->getBodyParam('convertMenuTo');
        $sectionId = $request->getBodyParam('section');
        $entryTypeId = $request->getBodyParam('entrytype');
        $navId = $request->getBodyParam('navigation');

        $menuModel = new MenuModel();
        $menuModel->convertMenuTo = $convertMenuTo;
        $menuModel->sectionId = $sectionId;
        $menuModel->entryTypeId = $entryTypeId;
        $menuModel->navId = $navId;
        $menuModel->setScenario('convert-menu');

        if (!$menuModel->validate()) {
            $validate = false;
        }

        $entryModel = new EntryModel();
        $entryModel->sectionId = $sectionId;
        $entryModel->entryTypeId = $entryTypeId;
        $entryModel->setScenario('convert-menu');

        if ($sectionId && !$entryModel->validate()) {
            $validate = false;
        }

        $fieldDefinitions = $cache->get('migrate-from-wordpress-menu-' . $menuId . '-fields');
        $fieldDefinitions = json_decode($fieldDefinitions, true);

        $fieldDefinitionModelArray = [];
        foreach ($postedFields as $key => $postedField) {
            // Set convert value for disabled lightswitch
            if (!isset($postedField['convert'])) {
                $convertField = 0;
            } else {
                $convertField = $postedField['convert'];
            }
            if (!isset($postedField['convertTo'])) {
                $convertTo = null;
            } else {
                $convertTo = $postedField['convertTo'];
            }
            //
            if (!$convertField) {
                continue;
            }
            //
            $fieldDefinitionModel = new FieldDefinitionModel();
            $fieldDefinitionModel->convert = $convertField;
            $fieldDefinitionModel->convertTo = $convertTo;
            $craftField = explode('--', $postedField['craftField']);
            $fieldDefinitionModel->craftField = $craftField[0];
            $fieldDefinitionModel->containerField = $postedField['containerField'];
            $fieldDefinitionModel->handle = $key;
            if (!isset($fieldDefinitions[$key]['config']['label'])) {
                Craft::error('no label for ' . $key);
                throw new ServerErrorHttpException('no label for ' . $key);
            }
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
            $fieldDefinitionModelArray[$key] = $fieldDefinitionModel;
        }
        $cache->set('migrate-from-wordpress-menu-' . $menuId . '-fields', json_encode($fieldDefinitions), 0, new TagDependency(['tags' => 'migrate-from-wordpress']));
        $fields = $postedFields;

        if (!$validate) {
            $this->setFailFlash(Craft::t('migrate-from-wordpress', 'There was some validation error.'));
            /** @var UrlManager $urlManager */
            $urlManager = Craft::$app->getUrlManager();
            $urlManager->setRouteParams([
                'fieldsModel' => $fieldDefinitionModelArray,
                'siteModel' => $siteModelArray,
                'entryModel' => $entryModel,
                'menuModel' => $menuModel,
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
        $uuidField = 'field_wordpressUUID_' . $uuidField->columnSuffix;
        //

        $fieldMappings = [];
        $fieldMappingsExtra = [];

        $fieldMappings['title']['attribute'] = true;
        $fieldMappings['title']['node'] = 'title/value';
        $fieldMappings['title']['default'] = '';

        $fieldMappings['parent']['attribute'] = true;
        $fieldMappings['parent']['node'] = 'parent/value';
        $fieldMappings['parent']['default'] = '';
        $fieldMappings['parent']['options']['match'] = $uuidField;

        $fields['wordpressLink']['type'] = 'text';
        $fields['wordpressLink']['convertTo'] = 'craft\fields\PlainText';
        $fields['wordpressLink']['convert'] = 1;
        $fields['wordpressLink']['craftField'] = 'wordpressLink';

        $fieldMappingsExtra['parent']['attribute'] = true;
        $fieldMappingsExtra['parent']['node'] = 'parent/value';
        $fieldMappingsExtra['parent']['default'] = '';
        $fieldMappingsExtra['parent']['options']['match'] = $uuidField;

        $fieldMappingsExtra['title']['attribute'] = true;
        $fieldMappingsExtra['title']['node'] = 'title/value';
        $fieldMappingsExtra['title']['default'] = '';

        $fieldMappingsExtra['wordpressMenuId']['attribute'] = true;
        $fieldMappingsExtra['wordpressMenuId']['node'] = 'wordpressMenuId/value';
        $fieldMappingsExtra['wordpressMenuId']['default'] = '';

        $fields['wordpressMenuId']['type'] = 'text';
        $fields['wordpressMenuId']['convertTo'] = 'craft\fields\PlainText';
        $fields['wordpressMenuId']['convert'] = 1;
        $fields['wordpressMenuId']['craftField'] = 'wordpressMenuId';

        $fields['wordpressUUID']['type'] = 'text';
        $fields['wordpressUUID']['convertTo'] = 'craft\fields\PlainText';
        $fields['wordpressUUID']['convert'] = 1;
        $fields['wordpressUUID']['craftField'] = 'wordpressUUID';

        $tabs = [];
        $fieldItems = [];
        if ($entryTypeId) {
            $entryType = Craft::$app->sections->getEntryTypeById($entryTypeId);
            $fieldLayout = $entryType->getFieldLayout();
            $tabs = $fieldLayout->getTabs();
            if (count($tabs) == 0) {
                $tab = new FieldLayoutTab([
                    'name' => 'tab1',
                    'layoutId' => $fieldLayout->id,
                    'sortOrder' => 99,
                ]);
                $tabs[] = $tab;
            }
        } elseif ($navId) {
            $nav = NavigationPlugin::getInstance()->navs->getNavById($navId);
            $fieldLayout = $nav->getFieldLayout();
            $tabs = $fieldLayout->getTabs();
            if (count($tabs) == 0) {
                $tab = new FieldLayoutTab([
                    'name' => 'Fields',
                    'layoutId' => $fieldLayout->id,
                    'sortOrder' => 99,
                ]);
                $tabs[] = $tab;
            }
        } else {
            throw new ServerErrorHttpException('menu should be migrated to entry type or navigation');
        }

        foreach ($fields as $key => $fieldSettings) {
            if ($key != 'title' && $key != 'termParent' && isset($fieldSettings['convertTo'])) {
                if (isset($fieldSettings['convert']) && $fieldSettings['convert']) {
                    FieldHelper::createFields($key, $fieldSettings, $fieldMappings, $fieldItem, $fieldMappingsExtra, 'menu', $fieldDefinitions, $menuId);
                    if (!$fieldItem) {
                        throw new ServerErrorHttpException('field item can\'t be blank');
                    }
                    $fieldItems[] = $fieldItem;
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

        $notInTab = false;
        if ($fieldItems) {
            foreach ($fieldItems as $key => $fieldItem) {
                foreach ($tabs as $tab) {
                    foreach ($tab->elements as $element) {
                        if ($element instanceof CustomField) {
                            if ($element->attribute() == $fieldItem->handle) {
                                $notInTab = true;
                                break 2;
                            }
                        }
                    }
                }

                if (!$notInTab && !in_array($fieldItem->id, $parsedFields)) {
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

        if ($entryTypeId) {
            $fieldLayout = Craft::$app->getFields()->createLayout($layoutModel);
            $fieldLayout->type = Entry::class;
            $entryType->setFieldLayout($fieldLayout);

            // Save it
            if (!Craft::$app->getSections()->saveEntryType($entryType)) {
                throw new ServerErrorHttpException('can\'t save entry type');
            }
        } elseif ($navId) {
            $fieldLayout = Craft::$app->getFields()->createLayout($layoutModel);
            $fieldLayout->type = NodeElement::class;
            $nav->setFieldLayout($fieldLayout);
            NavigationPlugin::$plugin->navs->saveNav($nav);
        } else {
            $cache->set('migrate-from-wordpress-menu-field-layout-' . $menuId, $fieldItems);
        }

        $migrateFeed = new MigrateFeed();
        $migrateFeed->fieldMappings = $fieldMappings;
        $migrateFeed->fieldMappingsExtra = $fieldMappingsExtra;
        $migrateFeed->typeId = $menuId;
        $migrateFeed->siteSettings = $siteSettings;
        $migrateFeed->itemType = 'menu';
        if ($sectionId) {
            $migrateFeed->sectionId = $sectionId;
            $migrateFeed->entryTypeId = $entryTypeId;
        } elseif ($navId) {
            $migrateFeed->navigationId = $navId;
        }
        $migrateFeed->createFeed();

        $cache->set('migrate-from-wordpress-menu-siteSettings-' . $menuId, $siteSettings, 0, new TagDependency(['tags' => 'migrate-from-wordpress']));

        foreach ($siteSettings as $key => $siteSetting) {
            if ($siteSetting['convert'] == '1') {
                $feedStatus = 'feed';
            } else {
                $feedStatus = 'no feed';
            }
            Craft::$app->getCache()->set('migrate-from-wordpress-convert-status-menu-' . $key . '-' . $menuId, $feedStatus, 0, new TagDependency(['tags' => 'migrate-from-wordpress']));
        }

        Craft::$app->getSession()->setNotice(Craft::t('migrate-from-wordpress', 'feeds created.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Get values of WordPress menus.
     *
     * @param string $menuId
     * @param string $contentLanguage
     * @param string $token
     * @param int $page
     * @param int $limit
     * @param int $isUpdateFeed
     * @return Response
     */
    public function actionValues(string $menuId, string $contentLanguage = null, string $token = null, int $page = 1, int $limit = 20, $isUpdateFeed = 0): Response
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

        $cacheKey = 'migrate-from-wordpress-values-menus-' . $menuId . '-' . $contentLanguage . '-' . $page . '-' . $limit;
        $cacheFeedValuesSeconds = MigrateFromWordPressPlugin::$plugin->settings->cacheFeedValuesSeconds;
        if (!is_integer($cacheFeedValuesSeconds)) {
            throw new ServerErrorHttpException('cacheFeedValuesSeconds should be integer.');
        }
        list($data, $next) = Craft::$app->getCache()->getOrSet($cacheKey, function() use ($menuId, $contentLanguage, $newToken, $page, $limit, $isUpdateFeed) {
            $menuItem = new MenuItem($menuId, $page, $limit);
            list($contents, $hasNext) = $menuItem->getValues();

            $fieldDefinitions = Craft::$app->getCache()->get('migrate-from-wordpress-menu-' . $menuId . '-fields');
            $fieldDefinitions = json_decode($fieldDefinitions, true);

            $level = '';
            $data = [];
            foreach ($contents as $content) {
                $fieldValues = [];
                FieldHelper::analyzeFieldValues($content, $fieldValues, $level, $contentLanguage, 'menu', $fieldDefinitions);
                if ($fieldValues) {
                    $data[] = $fieldValues;
                }
            }

            $page++;
            if ($hasNext) {
                $next = Craft::getAlias('@web') . "/migrate-from-wordpress/menus/values?token=$newToken&menuId=$menuId" .
                    "&contentLanguage=" . $contentLanguage . "&page=" . $page . "&limit=" . $limit . "&isUpdateFeed=$isUpdateFeed";
            } else {
                $next = "";
            }

            if (count($data) == 0) {
                $data = null;
            }

            return array($data, $next);
        }, $cacheFeedValuesSeconds, new TagDependency(['tags' => 'migrate-from-wordpress']));

        return $this->asJson([
            'data' => $data,
            'next' => $next,
        ]);
    }
}
