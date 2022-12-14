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
use vnali\migratefromwordpress\items\NavigationItem;
use vnali\migratefromwordpress\MigrateFromWordPress as MigrateFromWordPressPlugin;
use vnali\migratefromwordpress\models\EntryModel;
use vnali\migratefromwordpress\models\FieldDefinitionModel;
use vnali\migratefromwordpress\models\NavigationModel;
use vnali\migratefromwordpress\models\SiteModel;

use yii\caching\TagDependency;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

class NavigationsController extends Controller
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
     * Prepare migrate WordPress navigations to Craft elements.
     *
     * @param string $navigationId
     * @param FieldDefinitionModel[] $fieldsModel
     * @param SiteModel[] $siteModel
     * @param EntryModel $entryModel
     * @param NavigationModel $navigationModel
     * @return Response
     */
    public function actionMigrate(string $navigationId = null, array $fieldsModel = null, array $siteModel = null, EntryModel $entryModel = null, NavigationModel $navigationModel = null): Response
    {
        $cache = Craft::$app->getCache();
        $availableNavigationTypes = $cache->get('migrate-from-wordpress-available-navigation-types');
        if (!$availableNavigationTypes) {
            $availableNavigationTypes = [];
        }
        if (!in_array($navigationId, ArrayHelper::getColumn($availableNavigationTypes, 'value'))) {
            throw new ForbiddenHttpException($navigationId . ' is not valid navigation!');
        }
        // Check if user converted
        GeneralHelper::hookCheckUserConvert();
        //
        $navigationCacheKy = 'migrate-from-wordpress-navigation-' . $navigationId . '-items';
        $variables = [];
        $variables = Craft::$app->getCache()->getOrSet($navigationCacheKy, function() use ($navigationId, $availableNavigationTypes) {
            $variables['entrytypes'][] = ['value' => '', 'label' => Craft::t('migrate-from-wordpress', 'select one')];

            $navigationItem = new NavigationItem($navigationId, 1, 1);
            $fieldDefinitions = $navigationItem->getFieldDefinitions();

            $variables['navigationId'] = $navigationId;
            $variables['navigationLabel'] = $availableNavigationTypes[$navigationId]['label'];

            $variables['convertNavigationTo'] = [
                ['value' => '', 'label' => Craft::t('migrate-from-wordpress', 'select one')],
                ['value' => 'entry', 'label' => Craft::t('migrate-from-wordpress', 'Entry')],
                ['value' => 'navigation', 'label' => Craft::t('migrate-from-wordpress', 'Navigation plugin')],
            ];

            unset($fieldDefinitions['wordpressNavigationId']);
            unset($fieldDefinitions['wordpressUUID']);
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
            $this->setFailFlash(Craft::t('migrate-from-wordpress', "'" . $variables['navigationLabel'] . "'" . ' navigation has no item to migrate'));
            $cache->set('migrate-from-wordpress-convert-status-navigation-' . $navigationId, 'no-data', 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));
            return $this->redirect('migrate-from-wordpress');
        }

        FieldHelper::hookWordPressLabelAndInfo($variables['fieldDefinitions']);

        $variables['fields'] = $fieldsModel;
        $variables['siteModel'] = $siteModel;
        $variables['entryModel'] = $entryModel;
        $variables['navigationModel'] = $navigationModel;
        $variables['sections'][] = ['value' => '', 'label' => Craft::t('migrate-from-wordpress', 'select one')];
        foreach (Craft::$app->sections->getSectionsByType('structure')  as $section) {
            $sections = [];
            $sections['value'] = $section->id;
            $sections['label'] = $section->name;
            $variables['sections'][] = $sections;
        }

        $variables['navs'][] = ['value' => '', 'label' => Craft::t('migrate-from-wordpress', 'select one')];
        if (Craft::$app->plugins->isPluginInstalled('navigation') && Craft::$app->plugins->isPluginEnabled('navigation')) {
            foreach (NavigationPlugin::$plugin->getNavs()->getAllNavs() as $nav) {
                $navs = [];
                $navs['value'] = $nav->id;
                $navs['label'] = $nav->name;
                $variables['navs'][] = $navs;
            }
        }

        return $this->renderTemplate('migrate-from-wordpress/_navigations', $variables);
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

        $navigationId = $request->get('navigationId');
        $convertNavigationTo = $request->getBodyParam('convertNavigationTo');
        $sectionId = $request->getBodyParam('section');
        $entryTypeId = $request->getBodyParam('entrytype');
        $navId = $request->getBodyParam('navigation');

        $navigationModel = new NavigationModel();
        $navigationModel->convertNavigationTo = $convertNavigationTo;
        $navigationModel->sectionId = $sectionId;
        $navigationModel->entryTypeId = $entryTypeId;
        $navigationModel->navId = $navId;
        $navigationModel->setScenario('convert-navigation');

        if (!$navigationModel->validate()) {
            $validate = false;
        }

        $entryModel = new EntryModel();
        $entryModel->sectionId = $sectionId;
        $entryModel->entryTypeId = $entryTypeId;
        $entryModel->setScenario('convert-navigation');

        if ($sectionId && !$entryModel->validate()) {
            $validate = false;
        }

        $fieldDefinitions = $cache->get('migrate-from-wordpress-navigation-' . $navigationId . '-fields');
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
        $cache->set('migrate-from-wordpress-navigation-' . $navigationId . '-fields', json_encode($fieldDefinitions), 0, new TagDependency(['tags' => 'migrate-from-wordpress']));
        $fields = $postedFields;

        if (!$validate) {
            $this->setFailFlash(Craft::t('migrate-from-wordpress', 'There was some validation error.'));
            /** @var UrlManager $urlManager */
            $urlManager = Craft::$app->getUrlManager();
            $urlManager->setRouteParams([
                'fieldsModel' => $fieldDefinitionModelArray,
                'siteModel' => $siteModelArray,
                'entryModel' => $entryModel,
                'navigationModel' => $navigationModel,
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

        $fields['wordpressLink']['type'] = 'text';
        $fields['wordpressLink']['convertTo'] = 'craft\fields\PlainText';
        $fields['wordpressLink']['convert'] = 1;
        $fields['wordpressLink']['craftField'] = 'wordpressLink';

        $fieldMappingsExtra['title']['attribute'] = true;
        $fieldMappingsExtra['title']['node'] = 'title/value';
        $fieldMappingsExtra['title']['default'] = '';

        $fieldMappingsExtra['wordpressNavigationId']['attribute'] = true;
        $fieldMappingsExtra['wordpressNavigationId']['node'] = 'wordpressNavigationId/value';
        $fieldMappingsExtra['wordpressNavigationId']['default'] = '';

        $fields['wordpressNavigationId']['type'] = 'text';
        $fields['wordpressNavigationId']['convertTo'] = 'craft\fields\PlainText';
        $fields['wordpressNavigationId']['convert'] = 1;
        $fields['wordpressNavigationId']['craftField'] = 'wordpressNavigationId';

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
            $nav = NavigationPlugin::$plugin->getNavs()->getNavById($navId);
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
            throw new ServerErrorHttpException('navigation should be migrated to entry type or navigation');
        }

        foreach ($fields as $key => $fieldSettings) {
            if ($key != 'title' && isset($fieldSettings['convertTo'])) {
                if (isset($fieldSettings['convert']) && $fieldSettings['convert']) {
                    FieldHelper::createFields($key, $fieldSettings, $fieldMappings, $fieldItem, $fieldMappingsExtra, 'navigation', $fieldDefinitions, $navigationId);
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
            NavigationPlugin::$plugin->getNavs()->saveNav($nav);
        } else {
            $cache->set('migrate-from-wordpress-navigation-field-layout-' . $navigationId, $fieldItems);
        }

        $migrateFeed = new MigrateFeed();
        $migrateFeed->fieldMappings = $fieldMappings;
        $migrateFeed->fieldMappingsExtra = $fieldMappingsExtra;
        $migrateFeed->typeId = $navigationId;
        $migrateFeed->siteSettings = $siteSettings;
        $migrateFeed->itemType = 'navigation';
        if ($sectionId) {
            $migrateFeed->sectionId = $sectionId;
            $migrateFeed->entryTypeId = $entryTypeId;
        } elseif ($navId) {
            $migrateFeed->navigationId = $navId;
        }
        $migrateFeed->createFeed();

        $cache->set('migrate-from-wordpress-navigation-siteSettings-' . $navigationId, $siteSettings, 0, new TagDependency(['tags' => 'migrate-from-wordpress']));

        foreach ($siteSettings as $key => $siteSetting) {
            if ($siteSetting['convert'] == '1') {
                $feedStatus = 'feed';
            } else {
                $feedStatus = 'no feed';
            }
            Craft::$app->getCache()->set('migrate-from-wordpress-convert-status-navigation-' . $key . '-' . $navigationId, $feedStatus, 0, new TagDependency(['tags' => 'migrate-from-wordpress']));
        }

        Craft::$app->getSession()->setNotice(Craft::t('migrate-from-wordpress', 'feeds created.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Get values of WordPress navigations.
     *
     * @param string $navigationId
     * @param string $contentLanguage
     * @param string $token
     * @param int $page
     * @param int $limit
     * @return Response
     */
    public function actionValues(string $navigationId, string $contentLanguage = null, string $token = null, int $page = 1, int $limit = 20): Response
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

        $cacheKey = 'migrate-from-wordpress-values-navigations-' . $navigationId . '-' . $contentLanguage . '-' . $page . '-' . $limit;
        $cacheFeedValuesSeconds = MigrateFromWordPressPlugin::$plugin->settings->cacheFeedValuesSeconds;
        if (!is_integer($cacheFeedValuesSeconds)) {
            throw new ServerErrorHttpException('cacheFeedValuesSeconds should be integer.');
        }
        list($data, $next) = Craft::$app->getCache()->getOrSet($cacheKey, function() use ($navigationId, $contentLanguage, $newToken, $page, $limit) {
            $navigationItem = new NavigationItem($navigationId, $page, $limit);
            list($contents, $hasNext) = $navigationItem->getValues();

            $fieldDefinitions = Craft::$app->getCache()->get('migrate-from-wordpress-navigation-' . $navigationId . '-fields');
            $fieldDefinitions = json_decode($fieldDefinitions, true);

            $level = '';
            $data = [];
            foreach ($contents as $content) {
                $fieldValues = [];
                FieldHelper::analyzeFieldValues($content, $fieldValues, $level, $contentLanguage, 'navigation', $fieldDefinitions);
                if ($fieldValues) {
                    $data[] = $fieldValues;
                }
            }

            $page++;
            if ($hasNext) {
                $next = Craft::getAlias('@web') . "/migrate-from-wordpress/navigations/values?token=$newToken&navigationId=$navigationId" .
                    "&contentLanguage=" . $contentLanguage . "&page=" . $page . "&limit=" . $limit;
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
