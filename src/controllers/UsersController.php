<?php

namespace vnali\migratefromwordpress\controllers;

use Craft;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\StringHelper;
use craft\models\FieldLayoutTab;
use craft\web\Controller;
use craft\web\UrlManager;

use vnali\migratefromwordpress\helpers\FieldHelper;
use vnali\migratefromwordpress\helpers\GeneralHelper;
use vnali\migratefromwordpress\helpers\SiteHelper;
use vnali\migratefromwordpress\items\UserItem;
use vnali\migratefromwordpress\MigrateFromWordPress as MigrateFromWordPressPlugin;
use vnali\migratefromwordpress\models\FieldDefinitionModel;
use vnali\migratefromwordpress\models\UserItemModel;

use yii\caching\TagDependency;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

class UsersController extends Controller
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
     * Prepare to migrate WordPress users to Craft users.
     *
     * @param UserItemModel|null $userItemModel
     * @param FieldDefinitionModel[]|null $fieldsModel
     * @return Response
     */
    public function actionMigrate(UserItemModel $userItemModel = null, array $fieldsModel = null): Response
    {
        $variables = [];
        $userCacheKey = 'migrate-from-wordpress-user-items';
        $variables = Craft::$app->getCache()->getOrSet($userCacheKey, function() use ($variables) {
            $UserItem = new UserItem(1, 1);
            $fieldDefinitions = $UserItem->getFieldDefinitions();

            unset($fieldDefinitions['wordpressSiteId']);
            unset($fieldDefinitions['wordpressUserId']);
            unset($fieldDefinitions['wordpressUUID']);
            unset($fieldDefinitions['wordpressLink']);
            unset($fieldDefinitions['lang']);
            unset($fieldDefinitions['yoastSEO']);

            $variables['fieldDefinitions'] = $fieldDefinitions;
            $variables['nameFields'] = FieldHelper::filterFieldsByType($fieldDefinitions, 'plain text');
            $variables['userPictureFields'] = FieldHelper::filterFieldsByType($fieldDefinitions, 'asset');

            $view = $this->getView();
            $variables = GeneralHelper::View($view, $variables);

            return $variables;
        }, 0, new TagDependency(['tags' => 'migrate-from-wordpress']));

        FieldHelper::hookWordPressLabelAndInfo($variables['fieldDefinitions']);

        if ($userItemModel === null) {
            $userItemModel = new UserItemModel();
            $userItemModel->wordpressLanguage = '';
            $userItemModel->wordpressFullNameField = '';
            $userItemModel->wordpressUserPictureField = '';
            $variables['userItem'] = $userItemModel;
        } else {
            $variables['userItem'] = $userItemModel;
        }

        $variables['wordpressLanguages'] = SiteHelper::availableWordPressLanguages();
        $variables['fields'] = $fieldsModel;
        $volumeUid = Craft::$app->getProjectConfig()->get('users.photoVolumeUid');
        $showPhotoField = $volumeUid && Craft::$app->getVolumes()->getVolumeByUid($volumeUid);
        $variables['showPhotoField'] = $showPhotoField;

        return $this->renderTemplate('migrate-from-wordpress/_user', $variables);
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

        $postedFields = $request->getRequiredBodyParam('fields');
        if (!$postedFields) {
            $postedFields = [];
        }

        $validate = true;
        $cache = Craft::$app->getCache();
        $userItemModel = new UserItemModel();
        $userItemModel->wordpressLanguage = $request->getRequiredBodyParam('wordpressLanguage');
        $userItemModel->wordpressFullNameField = $request->getRequiredBodyParam('wordpressFullNameField');
        $userItemModel->wordpressUserPictureField = $request->getBodyParam('wordpressUserPictureField');
        if (!$userItemModel->validate()) {
            $validate = false;
        }

        $fieldDefinitions = $cache->get('migrate-from-wordpress-user-fields');
        $fieldDefinitions = json_decode($fieldDefinitions, true);
        $fieldDefinitionModelArray = [];

        foreach ($postedFields as $key => $postedField) {
            if (!isset($postedField['convert'])) {
                $convertField = 0;
            } else {
                $convertField = $postedField['convert'];
            }

            $fieldDefinitionModel = new FieldDefinitionModel();
            $fieldDefinitionModel->convert = $convertField;
            $fieldDefinitionModel->convertTo = $postedField['convertTo'];
            $craftField = explode('--', $postedField['craftField']);
            $fieldDefinitionModel->craftField = $craftField[0];
            $fieldDefinitionModel->containerField = $postedField['containerField'];
            $fieldDefinitionModel->handle = $key;
            if (!isset($fieldDefinitions[$key]['config']['label'])) {
                Craft::error('Label not found for ' . $key, __METHOD__);
                throw new ServerErrorHttpException('Label not found for ' . $key);
            }
            $fieldDefinitionModel->label = $fieldDefinitions[$key]['config']['label'];
            $fieldDefinitionModel->wordpressType = $fieldDefinitions[$key]['config']['type'];
            if (!$fieldDefinitionModel->validate()) {
                $validate = false;
            }
            $fieldDefinitions[$key]['convert'] = $convertField;
            $fieldDefinitions[$key]['convertTarget'] = $postedField['convertTo'];
            $fieldDefinitionModelArray[$key] = $fieldDefinitionModel;
        }
        $cache->set('migrate-from-wordpress-user-fields', json_encode($fieldDefinitions), 0, new TagDependency(['tags' => 'migrate-from-wordpress']));
        $fields = $postedFields;

        if (!$validate) {
            Craft::info('User item not saved due to validation error.', __METHOD__);
            $this->setFailFlash(Craft::t('migrate-from-wordpress', 'There was some validation error.'));
            /** @var UrlManager $urlManager */
            $urlManager = Craft::$app->getUrlManager();
            $urlManager->setRouteParams([
                'userItemModel' => $userItemModel,
                'fieldsModel' => $fieldDefinitionModelArray,
            ]);
            return null;
        }

        $fieldMappings = [];
        $fieldMappingsExtra = [];

        $fields['wordpressUUID']['type'] = 'text';
        $fields['wordpressUUID']['convertTo'] = 'craft\fields\PlainText';
        $fields['wordpressUUID']['convert'] = 1;
        $fields['wordpressUUID']['craftField'] = 'wordpressUUID';
        $fields['wordpressUUID']['translatable'] = 'none';

        $fields['wordpressLink']['type'] = 'text';
        $fields['wordpressLink']['convertTo'] = 'craft\fields\PlainText';
        $fields['wordpressLink']['convert'] = 1;
        $fields['wordpressLink']['craftField'] = 'wordpressLink';

        if ($userItemModel->wordpressFullNameField) {
            $fieldMappings['fullName']['attribute'] = true;
            $fieldMappings['fullName']['node'] = $userItemModel->wordpressFullNameField . '/value';
            $fieldMappings['fullName']['default'] = '';
        } else {
            $fieldMappings['fullName']['attribute'] = true;
            $fieldMappings['fullName']['node'] = 'name/value';
            $fieldMappings['fullName']['default'] = '';
        }

        if ($userItemModel->wordpressUserPictureField) {
            $fieldMappings['photoId']['attribute'] = true;
            $fieldMappings['photoId']['node'] = $userItemModel->wordpressUserPictureField . '/value';
            $fieldMappings['photoId']['default'] = '';
            $fieldMappings['photoId']['options']['upload'] = "1";
            $fieldMappings['photoId']['options']['conflict'] = "index";
        }

        $fieldMappingsExtra['wordpressUserId']['field'] = 'craft\fields\PlainText';
        $fieldMappingsExtra['wordpressUserId']['default'] = '';
        $fieldMappingsExtra['wordpressUserId']['node'] = 'wordpressUserId/value';

        $fields['wordpressSiteId']['type'] = 'text';
        $fields['wordpressSiteId']['convertTo'] = 'craft\fields\PlainText';
        $fields['wordpressSiteId']['convert'] = 1;
        $fields['wordpressSiteId']['craftField'] = 'wordpressSiteId';

        $fieldMappings['username']['attribute'] = true;
        $fieldMappings['username']['node'] = 'username/value';
        $fieldMappings['username']['default'] = '';

        $fieldMappings['email']['attribute'] = true;
        $fieldMappings['email']['node'] = 'email/value';
        $fieldMappings['email']['default'] = '';

        $fieldMappings['status']['attribute'] = true;
        $fieldMappings['status']['node'] = 'status/value';
        $fieldMappings['status']['default'] = '';

        $fields['wordpressUserId']['type'] = 'text';
        $fields['wordpressUserId']['convertTo'] = 'craft\fields\PlainText';
        $fields['wordpressUserId']['convert'] = 1;
        $fields['wordpressUserId']['craftField'] = 'wordpressUserId';
        $fields['wordpressUserId']['translatable'] = 'none';

        $user = User::find()->one();
        $fieldLayout = $user->getFieldLayout();
        $tabs = $fieldLayout->getTabs();

        if (count($tabs) == 0) {
            $tab = new FieldLayoutTab([
                'name' => 'Tab 1',
                'layoutId' => $fieldLayout->id,
                'sortOrder' => 99,
            ]);
            $tabs = [];
            $tabs[] = $tab;
        }

        $fieldItems = [];
        $fieldItem = null;

        foreach ($fields as $key => $fieldSettings) {
            if (
                isset($fieldSettings['convertTo']) &&
                isset($fieldSettings['convert']) &&
                $fieldSettings['convert'] == '1' &&
                // We don't need to convert fields that we already convert them as attribute.
                $key != $userItemModel->wordpressFullNameField &&
                $key != $userItemModel->wordpressUserPictureField
            ) {
                FieldHelper::createFields($key, $fieldSettings, $fieldMappings, $fieldItem, $fieldMappingsExtra, 'user', $fieldDefinitions);
                if (!$fieldItem) {
                    throw new ServerErrorHttpException('user field can\'t be null');
                }
                $fieldItems[] = $fieldItem;
            }
        }

        $parsedFields = [];
        $layoutModel = [];
        $layoutModel['tabs'] = [];
        $newTab = [];
        $newTabSuffix = StringHelper::randomString(5);
        $newTab['name'] = 'new tab ' . $newTabSuffix;
        $newTab['uid'] = StringHelper::UUID();

        foreach ($tabs as $tab) {
            if ($tab->elements) {
                $layoutModel['tabs'][] = $tab;
            }
        }

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
        array_unshift($layoutModel['tabs'], $newTab);
        $layoutModel['uid'] = $fieldLayout->uid;
        $layoutModel['id'] = $fieldLayout->id;

        $fieldLayout = Craft::$app->getFields()->createLayout($layoutModel);
        $fieldLayout->type = User::class;
        if (!Craft::$app->users->saveLayout($fieldLayout)) {
            throw new ServerErrorHttpException('user layout cannot be saved.');
        }

        UserItem::createFeed($fieldMappings, $userItemModel->wordpressLanguage);

        Craft::$app->getCache()->set('migrate-from-wordpress-convert-status-user', 'feed', 0, new TagDependency(['tags' => 'migrate-from-wordpress']));
        Craft::$app->getSession()->setNotice(Craft::t('migrate-from-wordpress', 'user migration feeds created.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Get values of WordPress users.
     *
     * @param string $contentLanguage
     * @param string $token
     * @param int $page
     * @param int $limit
     * @return Response
     */
    public function actionValues(string $contentLanguage = null, string $token = null, int $page = 1, int $limit = 10): Response
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

        $cacheKey = 'migrate-from-wordpress-users-values-' . $contentLanguage . '-' . $page . '-' . $limit;
        $cacheFeedValuesSeconds = MigrateFromWordPressPlugin::$plugin->settings->cacheFeedValuesSeconds;
        if (!is_integer($cacheFeedValuesSeconds)) {
            throw new ServerErrorHttpException('cacheFeedValuesSeconds should be integer.');
        }
        list($data, $next) = Craft::$app->getCache()->getOrSet($cacheKey, function() use ($contentLanguage, $newToken, $page, $limit) {
            $userItem = new UserItem($page, $limit, $contentLanguage);
            list($contents, $hasNext) = $userItem->getValues();

            $fieldDefinitions = Craft::$app->cache->get('migrate-from-wordpress-user-fields');
            $fieldDefinitions = json_decode($fieldDefinitions, true);

            $data = [];
            $level = '';
            foreach ($contents as $content) {
                $fieldValues = [];
                FieldHelper::analyzeFieldValues($content, $fieldValues, $level, $contentLanguage, 'user', $fieldDefinitions);
                if ($fieldValues) {
                    $data[] = $fieldValues;
                }
            }

            if (count($data) == 0) {
                $data = null;
            }

            $page = $page + 1;
            if ($hasNext) {
                $next = Craft::getAlias('@web') . "/migrate-from-wordpress/users/values?token=$newToken&contentLanguage=" .
                    $contentLanguage . "&page=" . $page . "&limit=" . $limit;
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
