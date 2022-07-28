<?php

namespace vnali\migratefromwordpress\controllers;

use Craft;
use craft\elements\Asset;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\StringHelper;
use craft\models\FieldLayoutTab;
use craft\web\Controller;
use craft\web\UrlManager;

use vnali\migratefromwordpress\helpers\FieldHelper;
use vnali\migratefromwordpress\helpers\GeneralHelper;
use vnali\migratefromwordpress\items\FileItem;
use vnali\migratefromwordpress\MigrateFromWordPress as MigrateFromWordPressPlugin;
use vnali\migratefromwordpress\models\FieldDefinitionModel;
use vnali\migratefromwordpress\models\VolumeModel;

use yii\caching\TagDependency;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

class FilesController extends Controller
{
    /**
     * @inheritDoc
     */
    protected array|int|bool $allowAnonymous = ['values'];

    /**
     * @inheritDoc
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
     * Prepare migrate WordPress files to Craft assets.
     *
     * @param VolumeModel $volumeModel
     * @param FieldDefinitionModel[] $fieldsModel
     * @return Response
     */
    public function actionMigrate(VolumeModel $volumeModel = null, array $fieldsModel = null): Response
    {
        $cache = Craft::$app->getCache();

        // Check if user converted
        GeneralHelper::hookCheckUserConvert();

        $variables = [];
        $variables = $cache->getOrSet('migrate-from-wordpress-file-items', function() {
            $variables['titleOptions'] = [
                'media-title' => 'media title',
                'media-alt' => 'media alt',
                'media-caption' => 'media caption',
                'auto-generate' => 'auto generate',
            ];

            $fileItem = new FileItem(1, 1);
            $fieldDefinitions = $fileItem->getFieldDefinitions();

            unset($fieldDefinitions['wordpressSiteId']);
            unset($fieldDefinitions['wordpressUUID']);
            unset($fieldDefinitions['wordpressLink']);
            unset($fieldDefinitions['wordpressFileId']);
            unset($fieldDefinitions['lang']);
            unset($fieldDefinitions['title']);
            unset($fieldDefinitions['filename']);
            unset($fieldDefinitions['folder']);
            unset($fieldDefinitions['uploaderUUID']);
            unset($fieldDefinitions['urlOrPath']);
            unset($fieldDefinitions['AssetId']);

            $variables['fieldDefinitions'] = $fieldDefinitions;

            $view = $this->getView();
            $variables = GeneralHelper::View($view, $variables);
            return $variables;
        }, 0, new TagDependency(['tags' => 'migrate-from-wordpress']));

        if (!is_array($variables['fieldDefinitions'])) {
            $this->setFailFlash(Craft::t('migrate-from-wordpress', 'No media to migrate'));
            $cache->set('migrate-from-wordpress-convert-status-file', 'no-data', 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));
            return $this->redirect('migrate-from-wordpress');
        }

        FieldHelper::hookWordPressLabelAndInfo($variables['fieldDefinitions']);

        $variables['volumes'][] = ['value' => '', 'label' => Craft::t('migrate-from-wordpress', 'select volume')];
        foreach (Craft::$app->volumes->getAllVolumes()  as $volumeItem) {
            $volume['value'] = $volumeItem->id;
            $volume['label'] = $volumeItem->name;
            $variables['volumes'][] = $volume;
        }

        // Get WordPress users
        $wordpressUsers = User::find()->all();
        foreach ($wordpressUsers as $wordpressUser) {
            if (!isset($wordpressUser->wordpressUUID)) {
                $variables['wordpressUsers'][] = null;
                break;
            }
            $user = [];
            $user['label'] = $wordpressUser->username;
            $user['value'] = $wordpressUser->wordpressUUID;
            $variables['wordpressUsers'][] = $user;
        }

        $variables['volumeModel'] = $volumeModel;
        $variables['fields'] = $fieldsModel;

        return $this->renderTemplate('migrate-from-wordpress/_file', $variables);
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
        $cache = Craft::$app->getCache();

        $siteSettings = $request->getBodyParam('sites');
        $cache->set('siteSettings', $siteSettings);

        $condition = $request->getBodyParam('condition');

        $volumeId = (int) $request->getBodyParam('volume');
        $volumeModel = new VolumeModel();
        $volumeModel->volumeId = $volumeId;
        $volumeModel->titleOption = $condition['titleOption'];
        $volumeModel->useAltNativeField = $condition['useAltNativeField'];
        $volumeModel->setScenario('convert-file');
        if (!$volumeModel->validate()) {
            $validate = false;
        }

        $fieldDefinitions = $cache->get('migrate-from-wordpress-file-fields');
        $fieldDefinitions = json_decode($fieldDefinitions, true);

        $fieldDefinitionModelArray = [];
        foreach ($postedFields as $key => $postedField) {
            // Set convert value for disabled lightswitch
            if (!isset($postedField['convert'])) {
                $convertField = 0;
            } else {
                $convertField = $postedField['convert'];
            }

            $fieldDefinitionModel = new FieldDefinitionModel();
            $fieldDefinitionModel->convert = $convertField;
            $fieldDefinitionModel->convertTo = $postedField['convertTo'];
            $fieldDefinitionModel->craftField = $postedField['craftField'];
            $fieldDefinitionModel->containerField = $postedField['containerField'];
            $fieldDefinitionModel->handle = $key;
            $fieldDefinitionModel->label = $fieldDefinitions[$key]['config']['label'];
            $fieldDefinitionModel->wordpressType = $fieldDefinitions[$key]['config']['type'];
            if (!$fieldDefinitionModel->validate()) {
                $validate = false;
            }
            $fieldDefinitionModelArray[$key] = $fieldDefinitionModel;
        }

        $fields = $postedFields;

        if (!$validate) {
            $this->setFailFlash(Craft::t('migrate-from-wordpress', 'not saved due to validation error.'));
            /** @var UrlManager $urlManager */
            $urlManager = Craft::$app->getUrlManager();
            $urlManager->setRouteParams([
                'fieldsModel' => $fieldDefinitionModelArray,
                'volumeModel' => $volumeModel,
            ]);
            return null;
        }

        $uniqueFileFeed = time() . rand();
        $cache->set('migrate-from-wordpress-file-posted-fields-' . $volumeId, json_encode($postedFields), 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));
        $cache->set('migrate-from-wordpress-file-posted-condition-' . $volumeId, json_encode($condition), 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));

        $fieldMappings = [];
        $fieldMappingsExtra = [];

        if (MigrateFromWordpressPlugin::$plugin->settings->fetchFilesByAssetIndex) {
            $fieldMappings['id']['attribute'] = true;
            $fieldMappings['id']['node'] = 'AssetId/value';
            $fieldMappings['id']['default'] = '';
        }

        $fieldMappings['title']['attribute'] = true;
        $fieldMappings['title']['node'] = 'title/value';
        $fieldMappings['title']['default'] = '';

        $fieldMappings['urlOrPath']['attribute'] = true;
        $fieldMappings['urlOrPath']['node'] = 'urlOrPath/value';
        $fieldMappings['urlOrPath']['default'] = '';
        $fieldMappings['urlOrPath']['options']['conflict'] = "index";

        $fieldMappings['filename']['attribute'] = true;
        $fieldMappings['filename']['node'] = 'filename/value';
        $fieldMappings['filename']['default'] = '';

        $fieldMappings['folderId']['attribute'] = true;
        $fieldMappings['folderId']['node'] = 'folder/value';
        $fieldMappings['folderId']['options']['default'] = '';
        $fieldMappings['folderId']['options']['create'] = "1";

        $fields['wordpressUUID']['type'] = 'text';
        $fields['wordpressUUID']['convertTo'] = 'craft\fields\PlainText';
        $fields['wordpressUUID']['convert'] = 1;
        $fields['wordpressUUID']['craftField'] = 'wordpressUUID';

        $fields['wordpressFileId']['type'] = 'text';
        $fields['wordpressFileId']['convertTo'] = 'craft\fields\PlainText';
        $fields['wordpressFileId']['convert'] = 1;
        $fields['wordpressFileId']['craftField'] = 'wordpressFileId';

        $fields['wordpressSiteId']['type'] = 'text';
        $fields['wordpressSiteId']['convertTo'] = 'craft\fields\PlainText';
        $fields['wordpressSiteId']['convert'] = 1;
        $fields['wordpressSiteId']['craftField'] = 'wordpressSiteId';

        $fields['wordpressLink']['type'] = 'text';
        $fields['wordpressLink']['convertTo'] = 'craft\fields\PlainText';
        $fields['wordpressLink']['convert'] = 1;
        $fields['wordpressLink']['craftField'] = 'wordpressLink';

        $volume = Craft::$app->volumes->getVolumeById($volumeId);
        $fieldLayout = $volume->getFieldLayout();
        $tabs = $fieldLayout->getTabs();
        $fieldItems = [];
        if (count($tabs) == 0) {
            $tab = new FieldLayoutTab([
                'name' => 'Tab 1',
                'layoutId' => $fieldLayout->id,
                'sortOrder' => 99,
            ]);
            $tabs = [];
            $tabs[] = $tab;
            $fieldItems = [];
        }

        $fieldItem = null;
        foreach ($fields as $key => $fieldSettings) {
            if ($key != 'title' && isset($fieldSettings['convertTo'])) {
                if ($fieldSettings['convert']) {
                    FieldHelper::createFields($key, $fieldSettings, $fieldMappings, $fieldItem, $fieldMappingsExtra, 'file', $fieldDefinitions);
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

        $layoutModel['tabs'][] = $newTab;
        $layoutModel['uid'] = $fieldLayout->uid;
        $layoutModel['id'] = $fieldLayout->id;

        $fieldLayout = Craft::$app->getFields()->createLayout($layoutModel);
        $fieldLayout->type = Asset::class;
        $volume->setFieldLayout($fieldLayout);
        // Save it
        if (!Craft::$app->volumes->saveVolume($volume)) {
            Craft::error('File volume can\'t save', __METHOD__);
        }

        // Create feed for migrating files.
        FileItem::createFeed($fieldMappings, $volumeId, $uniqueFileFeed);

        $cache->set('migrate-from-wordpress-convert-status-file', 'feed', 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));

        Craft::$app->getSession()->setNotice(Craft::t('migrate-from-wordpress', 'feeds created.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Get values of WordPress files.
     *
     * @param int $volumeId
     * @param string $uniqueFileFeed
     * @param string|null $token
     * @param int $page
     * @param int $limit
     * @throws ForbiddenHttpException
     * @return Response
     */
    public function actionValues(int $volumeId, string $uniqueFileFeed, string $token = null, int $page = 1, int $limit = 50): Response
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

        $cacheKey = 'migrate-from-wordpress-file-values-' . $volumeId . '-' . $page . '-' . $limit;
        $cacheFeedValuesSeconds = MigrateFromWordPressPlugin::$plugin->settings->cacheFeedValuesSeconds;
        list($data, $next) = Craft::$app->getCache()->getOrSet($cacheKey, function() use ($newToken, $page, $limit, $volumeId, $uniqueFileFeed) {
            $fileItem = new FileItem($page, $limit);
            list($contents, $hasNext) = $fileItem->getValues();

            $fieldDefinitions = Craft::$app->getCache()->get('migrate-from-wordpress-file-fields');
            $fieldDefinitions = json_decode($fieldDefinitions, true);

            $level = '';
            $data = [];
            foreach ($contents as $content) {
                $fieldValues = [];
                FieldHelper::analyzeFieldValues($content, $fieldValues, $level, 'en', 'file', $fieldDefinitions);
                if ($fieldValues) {
                    $data[] = $fieldValues;
                }
            }

            if (count($data) == 0) {
                $data = null;
            }

            $page = $page + 1;
            if ($hasNext) {
                $next = Craft::getAlias('@web') . "/migrate-from-wordpress/files/values?token=$newToken&limit=" . $limit . "&page=" .
                    $page . "&volumeId=" . $volumeId . "&uniqueFileFeed=" . $uniqueFileFeed;
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
