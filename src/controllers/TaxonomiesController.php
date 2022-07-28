<?php

namespace vnali\migratefromwordpress\controllers;

use Craft;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\StringHelper;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\FieldLayoutTab;
use craft\web\Controller;
use craft\web\UrlManager;

use vnali\migratefromwordpress\feed\MigrateFeed;
use vnali\migratefromwordpress\helpers\FieldHelper;
use vnali\migratefromwordpress\helpers\GeneralHelper;
use vnali\migratefromwordpress\helpers\SiteHelper;
use vnali\migratefromwordpress\items\TaxonomyItem;
use vnali\migratefromwordpress\MigrateFromWordPress as MigrateFromWordPressPlugin;
use vnali\migratefromwordpress\models\CategoryModel;
use vnali\migratefromwordpress\models\EntryModel;
use vnali\migratefromwordpress\models\FieldDefinitionModel;
use vnali\migratefromwordpress\models\SiteModel;
use vnali\migratefromwordpress\models\TagModel;
use vnali\migratefromwordpress\models\TaxonomyModel;

use yii\caching\TagDependency;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

class TaxonomiesController extends Controller
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
     * Prepare migrate WordPress taxonomies to Craft elements.
     *
     * @param string $taxonomyId
     * @param FieldDefinitionModel[] $fieldsModel
     * @param SiteModel[] $siteModel
     * @param EntryModel $entryModel
     * @param TagModel $tagModel
     * @param CategoryModel $categoryModel
     * @param TaxonomyModel $taxonomyModel
     * @return Response
     */
    public function actionMigrate(string $taxonomyId = null, array $fieldsModel = null, array $siteModel = null, EntryModel $entryModel = null, TagModel $tagModel = null, CategoryModel $categoryModel = null, TaxonomyModel $taxonomyModel = null): Response
    {
        $cache = Craft::$app->getCache();
        $availableTaxonomyTypes = $cache->get('migrate-from-wordpress-available-taxonomy-types');
        if (!$availableTaxonomyTypes) {
            $availableTaxonomyTypes = [];
        }
        if (!in_array($taxonomyId, $availableTaxonomyTypes)) {
            throw new ForbiddenHttpException($taxonomyId . " taxonomy type $taxonomyId is not valid!" . json_encode($availableTaxonomyTypes));
        }

        //check if user converted
        GeneralHelper::hookCheckUserConvert();
        //

        $variables = [];
        $taxonomyCacheKey = 'migrate-from-wordpress-taxonomy-' . $taxonomyId . '-items';
        $variables = Craft::$app->getCache()->getOrSet($taxonomyCacheKey, function() use ($taxonomyId) {
            $taxonomyItem = new TaxonomyItem($taxonomyId, 1, 1);
            $fieldDefinitions = $taxonomyItem->getFieldDefinitions();

            $variables['wordpressLanguages'] = SiteHelper::availableWordPressLanguages();

            $variables['taxonomyId'] = $taxonomyId;
            $variables['taxonomyLabel'] = 'Default';

            unset($fieldDefinitions['wordpressSiteId']);
            unset($fieldDefinitions['wordpressTermId']);
            unset($fieldDefinitions['wordpressUUID']);
            unset($fieldDefinitions['lang']);
            unset($fieldDefinitions['termParent']);
            unset($fieldDefinitions['tagUUID']);
            unset($fieldDefinitions['title']);
            //unset($fieldDefinitions['wordpressSourceLanguage']);
            unset($fieldDefinitions['wordpressLink']);

            $variables['fieldDefinitions'] = $fieldDefinitions;

            $view = $this->getView();
            $variables = GeneralHelper::View($view, $variables);

            return $variables;
        }, 0, new TagDependency(['tags' => 'migrate-from-wordpress']));

        $variables['craftSites'] = SiteHelper::availableCraftSites();

        if (!is_array($variables['fieldDefinitions'])) {
            $this->setFailFlash(Craft::t('migrate-from-wordpress', "'" . $variables['taxonomyLabel'] . "'" . " $taxonomyId " . 'has no item to migrate'));
            $cache->set('migrate-from-wordpress-convert-status-taxonomy-' . $taxonomyId, 'no-data', 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));
            return $this->redirect('migrate-from-wordpress');
        }

        $variables['sections'][] = ['value' => '', 'label' => Craft::t('migrate-from-wordpress', 'select one')];
        foreach (Craft::$app->sections->getAllSections() as $section) {
            $sections['value'] = $section->id;
            $sections['label'] = $section->name;
            $variables['sections'][] = $sections;
        }
        $variables['entrytypes'][] = ['value' => '', 'label' => Craft::t('migrate-from-wordpress', 'select one')];

        $variables['categories'][] = ['value' => '', 'label' => Craft::t('migrate-from-wordpress', 'select category group')];
        $variables['categories'][] = ['value' => '-1', 'label' => Craft::t('migrate-from-wordpress', '+ new category group')];
        foreach (Craft::$app->categories->getAllGroups()  as $categoryItem) {
            $category['value'] = $categoryItem->id;
            $category['label'] = $categoryItem->name;
            $variables['categories'][] = $category;
        }

        $variables['tags'][] = ['value' => '', 'label' => Craft::t('migrate-from-wordpress', 'select tag group')];
        $variables['tags'][] = ['value' => '-1', 'label' => Craft::t('migrate-from-wordpress', '+ new tag group')];
        foreach (Craft::$app->tags->getAllTagGroups()  as $tagItem) {
            $tag['value'] = $tagItem->id;
            $tag['label'] = $tagItem->name;
            $variables['tags'][] = $tag;
        }

        FieldHelper::hookWordPressLabelAndInfo($variables['fieldDefinitions']);

        $variables['fields'] = $fieldsModel;
        $variables['siteModel'] = $siteModel;
        $variables['entryModel'] = $entryModel;
        $variables['tagModel'] = $tagModel;
        $variables['categoryModel'] = $categoryModel;
        $variables['taxonomyModel'] = $taxonomyModel;
        return $this->renderTemplate('migrate-from-wordpress/_taxonomies', $variables);
    }

    /**
     * Process passed fields.
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
        $siteSettings = $request->getBodyParam('sites');

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
            $craftSiteIds[] = $siteSetting['convertTo'];
            if ($key == $endKey) { // -- this is the last item
                $siteModel->setScenario('lastLanguage');
                $siteModel->converts = $converts;
                //$siteModel->craftSiteIds = $craftSiteIds;
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
        $entryModel->setScenario('convert-taxonomy');
        if (!$entryModel->validate()) {
            $validate = false;
        }

        $categoryId = (int) $request->getBodyParam('category');
        $tagId = (int) $request->getBodyParam('tag');
        $groupName = null;
        $groupFieldName = null;
        if ($tagId) {
            $groupFieldName = $request->getBodyParam('tagGroupFieldName');
            $groupName = $request->getBodyParam('tagGroupName');
            $tagModel = new TagModel();
            $tagModel->tagId = $tagId;
            $tagModel->tagGroupName = $groupName;
            $tagModel->tagGroupHandle = $groupName;
            $tagModel->tagFieldHandle = $groupFieldName;
            $tagModel->setScenario('convert-taxonomy');
            if (!$tagModel->validate()) {
                $validate = false;
            }
        } elseif ($categoryId) {
            $groupFieldName = $request->getBodyParam('categoryGroupFieldName');
            $groupName = $request->getBodyParam('categoryGroupName');
            $categoryModel = new CategoryModel();
            $categoryModel->categoryId = $categoryId;
            $categoryModel->categoryGroupName = $groupName;
            $categoryModel->categoryGroupHandle = $groupName;
            $categoryModel->categoryFieldHandle = $groupFieldName;
            $categoryModel->setScenario('convert-taxonomy');
            if (!$categoryModel->validate()) {
                $validate = false;
            }
        }

        $requestedTaxonomy = $request->get('taxonomyId');
        $cache = Craft::$app->getCache();
        $availableVocabTypes = $cache->get('migrate-from-wordpress-available-taxonomy-types');
        if (!$availableVocabTypes) {
            $availableVocabTypes = [];
        }
        if (!in_array($requestedTaxonomy, $availableVocabTypes)) {
            throw new ForbiddenHttpException($requestedTaxonomy . ' vocab type is not valid!');
        }
        $fieldDefinitions = Craft::$app->getCache()->get('migrate-from-wordpress-taxonomy-' . $requestedTaxonomy . '-fields');
        $fieldDefinitions = json_decode($fieldDefinitions, true);

        $fieldDefinitionModelArray = [];
        foreach ($postedFields as $key => $postedField) {
            //set convert value for disabled lightswitch
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
            //add convert status to fieldDefinitions. needed for getting value
            $fieldDefinitions[$key]['convert'] = $convertField;
            //
            //add target craft type to fieldDefinitions. needed for getting value
            $fieldDefinitions[$key]['convertTarget'] = $postedField['convertTo'];
            //
            $fieldDefinitions[$key]['containerField'] = $postedField['containerField'];
            $fieldDefinitionModelArray[$key] = $fieldDefinitionModel;
        }
        $cache->set('migrate-from-wordpress-taxonomy-' . $requestedTaxonomy . '-fields', json_encode($fieldDefinitions), 0, new TagDependency(['tags' => 'migrate-from-wordpress']));
        $fields = $postedFields;

        $taxonomyModel = new TaxonomyModel();
        $taxonomyModel->sectionId = $sectionId;
        $taxonomyModel->tagId = $tagId;
        $taxonomyModel->categoryId = $categoryId;
        $taxonomyModel->tagGroupHandle = $groupName;
        $taxonomyModel->categoryGroupHandle = $groupName;
        $taxonomyModel->setScenario('convert-taxonomy');
        if (!$taxonomyModel->validate()) {
            $validate = false;
        }

        if (!$validate) {
            Craft::info('Taxonomy item not saved due to validation error.', __METHOD__);
            $this->setFailFlash(Craft::t('migrate-from-wordpress', 'Taxonomy item not saved due to validation error.'));
            /** @var UrlManager $urlManager */
            $urlManager = Craft::$app->getUrlManager();
            $urlManager->setRouteParams([
                'categoryModel' => $categoryModel ?? null,
                'fieldsModel' => $fieldDefinitionModelArray,
                'siteModel' => $siteModelArray,
                'entryModel' => $entryModel,
                'tagModel' => $tagModel ?? null,
                'taxonomyModel' => $taxonomyModel,
            ]);
            return null;
        }

        // Get exact name of column
        $uuidField = Craft::$app->fields->getFieldByHandle('wordpressLink');
        if (!$uuidField) {
            $newField = new \craft\fields\PlainText([
                "groupId" => 1,
                "name" => 'wordpressLink',
                "handle" => 'wordpressLink',
            ]);
            Craft::$app->fields->saveField($newField);
            $uuidField = Craft::$app->fields->getFieldByHandle('wordpressLink');
        }
        $uuidField = 'field_wordpressLink_' . $uuidField->columnSuffix;
        //

        $fieldMappings = [];
        $fieldMappingsExtra = [];

        $fieldMappings['title']['attribute'] = true;
        $fieldMappings['title']['node'] = 'title/value';
        $fieldMappings['title']['default'] = '';

        $fieldMappings['slug']['attribute'] = true;
        $fieldMappings['slug']['node'] = 'title/value';
        $fieldMappings['slug']['default'] = '';

        $fields['wordpressTermId']['type'] = 'text';
        $fields['wordpressTermId']['convertTo'] = 'craft\fields\PlainText';
        $fields['wordpressTermId']['convert'] = 1;
        $fields['wordpressTermId']['craftField'] = 'wordpressTermId';

        $fields['wordpressLink']['type'] = 'text';
        $fields['wordpressLink']['convertTo'] = 'craft\fields\PlainText';
        $fields['wordpressLink']['convert'] = 1;
        $fields['wordpressLink']['craftField'] = 'wordpressLink';

        $fieldMappings['parent']['attribute'] = true;
        $fieldMappings['parent']['node'] = 'termParent/value';
        $fieldMappings['parent']['default'] = '';
        $fieldMappings['parent']['options']['match'] = $uuidField;

        $fieldMappingsExtra['title']['attribute'] = true;
        $fieldMappingsExtra['title']['node'] = 'title/value';
        $fieldMappingsExtra['title']['default'] = '';

        if ($tagId || $categoryId) {
            $fieldMappingsExtra['wordpressTermId']['attribute'] = true;
            $fieldMappingsExtra['wordpressTermId']['node'] = 'wordpressTermId/value';
            $fieldMappingsExtra['wordpressTermId']['default'] = '';

            $fieldMappingsExtra['parent']['attribute'] = true;
            $fieldMappingsExtra['parent']['node'] = 'termParent/value';
            $fieldMappingsExtra['parent']['default'] = '';
            $fieldMappingsExtra['parent']['options']['match'] = $uuidField;
        } else {
            $fieldMappings['postDate']['attribute'] = true;
            $fieldMappings['postDate']['node'] = 'created/value';
            $fieldMappings['postDate']['default'] = '';
            $fieldMappings['postDate']['options']['match'] = "seconds";

            $fieldMappings['enabled']['attribute'] = true;
            $fieldMappings['enabled']['node'] = 'status/value';
            $fieldMappings['enabled']['default'] = '';

            $fieldMappings['authorId']['attribute'] = true;
            $fieldMappings['authorId']['node'] = 'authorId/value';
            $fieldMappings['authorId']['default'] = '';
            $fieldMappings['authorId']['options']['match'] = $uuidField;

            $fieldMappingsExtra['wordpressTermId']['attribute'] = true;
            $fieldMappingsExtra['wordpressTermId']['node'] = 'wordpressTermId/value';
            $fieldMappingsExtra['wordpressTermId']['default'] = '';

            $fieldMappingsExtra['parent']['attribute'] = true;
            $fieldMappingsExtra['parent']['node'] = 'termParent/value';
            $fieldMappingsExtra['parent']['default'] = '';
            $fieldMappingsExtra['parent']['options']['match'] = $uuidField;
        }

        $fields['wordpressUUID']['type'] = 'text';
        $fields['wordpressUUID']['convertTo'] = 'craft\fields\PlainText';
        $fields['wordpressUUID']['convert'] = 1;
        $fields['wordpressUUID']['craftField'] = 'wordpressUUID';

        $fields['wordpressSiteId']['type'] = 'text';
        $fields['wordpressSiteId']['convertTo'] = 'craft\fields\PlainText';
        $fields['wordpressSiteId']['convert'] = 1;
        $fields['wordpressSiteId']['craftField'] = 'wordpressSiteId';

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
                $tabs = [];
                $tabs[] = $tab;
                $fieldItems = [];
            }
        } elseif ($tagId || $categoryId) {
            if ($tagId) {
                if ($tagId == '-1') {
                    $group = new \craft\models\TagGroup([
                        "name" => $groupName,
                        "handle" => StringHelper::camelCase($groupName),
                    ]);
                    if (!Craft::$app->tags->saveTagGroup($group)) {
                        throw new ServerErrorHttpException('tag group can\'t save');
                    }
                    $tagId = $group->id;
                } else {
                    $group = Craft::$app->tags->getTagGroupById($tagId);
                }
            } else {
                if ($categoryId == '-1') {
                    $categorySiteSettings = [];
                    foreach (Craft::$app->getSites()->getAllSiteIds() as $siteId) {
                        $siteSetting = new CategoryGroup_SiteSettings([
                            'hasUrls' => true,
                            'uriFormat' => StringHelper::camelCase($groupName) . "/{slug}",
                            'template' => null,
                            'siteId' => $siteId,
                        ]);
                        $categorySiteSettings[$siteId] = $siteSetting;
                    }
                    $group = new \craft\models\CategoryGroup([
                        "name" => $groupName,
                        "handle" => StringHelper::camelCase($groupName),
                    ]);
                    $group->setSiteSettings($categorySiteSettings);
                    if (!Craft::$app->categories->saveGroup($group)) {
                        throw new ServerErrorHttpException('category group can\'t save' . json_encode($group->getErrors()));
                    }
                    $categoryId = $group->id;
                } else {
                    $group = Craft::$app->categories->getGroupById($categoryId);
                }
            }

            $fieldLayout = $group->getFieldLayout();
            $tabs = $fieldLayout->getTabs();
            if (count($tabs) == 0) {
                $tab = new FieldLayoutTab([
                    'name' => 'Content',
                    'layoutId' => $fieldLayout->id,
                    'sortOrder' => 99,
                ]);
                $tabs = [];
                $tabs[] = $tab;
            }
        } else {
            throw new ServerErrorHttpException('not supported');
        }

        $fieldItem = null;
        foreach ($fields as $key => $fieldSettings) {
            if ($key != 'title' && $key != 'termParent' && isset($fieldSettings['convertTo'])) {
                if (isset($fieldSettings['convert']) && $fieldSettings['convert']) {
                    FieldHelper::createFields($key, $fieldSettings, $fieldMappings, $fieldItem, $fieldMappingsExtra, 'taxonomy', $fieldDefinitions, $requestedTaxonomy);
                    if (!$fieldItem) {
                        Craft::error('field Item is empty', __METHOD__);
                        return null;
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
        //array_unshift($layoutModel['tabs'], $newTab);
        $layoutModel['tabs'][] = $newTab;
        $layoutModel['uid'] = $fieldLayout->uid;
        $layoutModel['id'] = $fieldLayout->id;

        // Assemble the layout
        if ($entryTypeId) {
            $fieldLayout = Craft::$app->getFields()->createLayout($layoutModel);
            $fieldLayout->type = Entry::class;
            $entryType->setFieldLayout($fieldLayout);

            // Save it
            if (!Craft::$app->getSections()->saveEntryType($entryType)) {
                Craft::error('entry type couldn\'t save', __METHOD__);
                return null;
            }
        } elseif ($tagId || $categoryId) {
            $fieldLayout = Craft::$app->getFields()->createLayout($layoutModel);
            if ($tagId) {
                $fieldLayout->type = Tag::class;
            } else {
                $fieldLayout->type = Category::class;
            }
            $group->setFieldLayout($fieldLayout);

            if ($tagId) {
                // Save it
                if (!Craft::$app->tags->saveTagGroup($group)) {
                    Craft::error('tag group couldn\'t save', __METHOD__);
                    return null;
                }
                $group = Craft::$app->tags->getTagGroupById($group->id);
                $field = new \craft\fields\Tags([
                    "groupId" => 1,
                    "name" => $groupFieldName,
                    "handle" => StringHelper::camelCase($groupFieldName),
                    "allowMultipleSources" => false,
                    "allowLimit" => false,
                    "sources" => "*",
                    "source" => "taggroup:" . $group->uid,
                ]);
                Craft::$app->getFields()->saveField($field);
            } else {
                // Save it
                if (!Craft::$app->categories->saveGroup($group)) {
                    Craft::error('category group couldn\'t save', __METHOD__);
                    return null;
                }
                $group = Craft::$app->categories->getGroupById($group->id);
                $field = new \craft\fields\Categories([
                    "groupId" => 1,
                    "name" => $groupFieldName,
                    "handle" => StringHelper::camelCase($groupFieldName),
                    "allowMultipleSources" => false,
                    "allowLimit" => false,
                    "sources" => "*",
                    "source" => "group:" . $group->uid,
                ]);
                Craft::$app->getFields()->saveField($field);
            }
        }

        $migrateFeed = new MigrateFeed();
        $migrateFeed->fieldMappings = $fieldMappings;
        $migrateFeed->fieldMappingsExtra = $fieldMappingsExtra;
        $migrateFeed->typeId = $requestedTaxonomy;
        $migrateFeed->siteSettings = $siteSettings;
        $migrateFeed->itemType = 'taxonomy';
        if ($entryTypeId) {
            $migrateFeed->sectionId = $sectionId;
            $migrateFeed->entryTypeId = $entryTypeId;
        } else {
            $migrateFeed->tagId = $tagId;
            $migrateFeed->categoryId = $categoryId;
        }
        $migrateFeed->createFeed();

        $cache->set('migrate-from-wordpress-taxonomy-siteSettings-' . $requestedTaxonomy, $siteSettings, 0, new TagDependency(['tags' => 'migrate-from-wordpress']));

        foreach ($siteSettings as $key => $siteSetting) {
            if ($siteSetting['convert'] == '1') {
                $feedStatus = 'feed';
            } else {
                $feedStatus = 'no feed';
            }
            $cache->set('migrate-from-wordpress-convert-status-taxonomy-' . $key . '-' . $requestedTaxonomy, $feedStatus, 0, new TagDependency(['tags' => 'migrate-from-wordpress']));
        }
        $cache->set('migrate-from-wordpress-convert-status-taxonomy-' . $requestedTaxonomy, 'feed', 0, new TagDependency(['tags' => 'migrate-from-wordpress']));

        Craft::$app->getSession()->setNotice(Craft::t('migrate-from-wordpress', 'feeds created.'));
        return $this->redirectToPostedUrl();
    }

    /**
     * Get values of WordPress categories.
     *
     * @param string $taxonomyId
     * @param string $contentLanguage
     * @param string $token
     * @param int $page
     * @param int $limit
     * @param int $isUpdateFeed
     * @param int $hasUpdateFeed
     * @return Response
     */
    public function actionValues(string $taxonomyId, string $contentLanguage = null, string $token = null, int $page = 1, int $limit = 30, int $isUpdateFeed = 0, int $hasUpdateFeed = 0): Response
    {
        //prevent other feeds from running when token is regenerating
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

        $cacheKey = 'migrate-from-wordpress-taxonomy-values-' . $taxonomyId . '-' . $contentLanguage . '-' . $page . '-' . $limit;
        $cacheFeedValuesSeconds = MigrateFromWordPressPlugin::$plugin->settings->cacheFeedValuesSeconds;
        if (!is_integer($cacheFeedValuesSeconds)) {
            throw new ServerErrorHttpException('cacheFeedValuesSeconds should be integer.');
        }
        list($data, $next) = Craft::$app->getCache()->getOrSet($cacheKey, function() use ($taxonomyId, $contentLanguage, $newToken, $limit, $page, $isUpdateFeed, $hasUpdateFeed) {
            $taxonomyItem = new TaxonomyItem($taxonomyId, $page, $limit, $contentLanguage);
            list($contents, $hasNext) = $taxonomyItem->getValues();

            $fieldDefinitions = Craft::$app->getCache()->get('migrate-from-wordpress-taxonomy-' . $taxonomyId . '-fields');
            $fieldDefinitions = json_decode($fieldDefinitions, true);
            $level = '';
            $data = [];
            foreach ($contents as $content) {
                $fieldValues = [];
                FieldHelper::analyzeFieldValues($content, $fieldValues, $level, $contentLanguage, 'taxonomy', $fieldDefinitions);
                if ($fieldValues) {
                    $data[] = $fieldValues;
                }
            }

            $page = $page + 1;
            if ($hasNext) {
                $next = Craft::getAlias('@web') . "/migrate-from-wordpress/taxonomies/values?token=$newToken&taxonomyId=$taxonomyId&contentLanguage=$contentLanguage&limit=$limit&page=$page&isUpdateFeed=$isUpdateFeed&hasUpdateFeed=$hasUpdateFeed";
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
