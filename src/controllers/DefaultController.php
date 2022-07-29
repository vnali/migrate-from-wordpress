<?php

namespace vnali\migratefromwordpress\controllers;

use Craft;
use craft\web\Controller;

use vnali\migratefromwordpress\helpers\Curl;
use vnali\migratefromwordpress\helpers\FieldHelper;
use vnali\migratefromwordpress\helpers\GeneralHelper;
use vnali\migratefromwordpress\helpers\SiteHelper;
use vnali\migratefromwordpress\MigrateFromWordPress as MigrateFromWordPressPlugin;

use Yii;
use yii\caching\TagDependency;
use yii\helpers\ArrayHelper;
use yii\web\Response;

class DefaultController extends Controller
{
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
     * List all WordPress items for migrate.
     * @return Response
     */
    public function actionIndex(): Response
    {
        $variables = Craft::$app->getCache()->getOrSet(
            'migrate-from-wordpress-items',
            function() {
                $cache = Craft::$app->getCache();
                $wordpressURL = MigrateFromWordPressPlugin::$plugin->settings->wordpressURL;
                $wordpressRestApiEndpoint = MigrateFromWordPressPlugin::$plugin->settings->wordpressRestApiEndpoint;
                // Navigation
                $address = $wordpressURL . '/' . $wordpressRestApiEndpoint . '/navigation';
                $response = Curl::sendToRestAPI($address);
                $response = json_decode($response);
                $variables['navigations'] = [];
                foreach ($response as $navigation) {
                    $item = [];
                    $item['value'] = $navigation->id;
                    $item['label'] = $navigation->title->rendered;
                    $variables['navigations'][$navigation->id] = $item;
                }
                $cache->set(
                    'migrate-from-wordpress-available-navigation-types',
                    $variables['navigations'],
                    0,
                    new TagDependency(['tags' => 'migrate-from-wordpress'])
                );
                // Menus
                $address = $wordpressURL . '/' . $wordpressRestApiEndpoint . '/menus';
                $response = Curl::sendToRestAPI($address);
                $response = json_decode($response);
                $variables['menus'] = [];
                foreach ($response as $menu) {
                    $item = [];
                    $item['value'] = $menu->id;
                    $item['label'] = $menu->name;
                    $variables['menus'][$menu->id] = $item;
                }
                $cache->set(
                    'migrate-from-wordpress-available-menu-types',
                    $variables['menus'],
                    0,
                    new TagDependency(['tags' => 'migrate-from-wordpress'])
                );
                // Taxonomies
                $variables['taxonomies'] = [];
                $taxonomy = [];
                $taxonomy['value'] = 'categories';
                $taxonomy['label'] = 'category';
                $variables['taxonomies'][] = $taxonomy;
                $taxonomy = [];
                $taxonomy['value'] = 'tags';
                $taxonomy['label'] = 'tag';
                $variables['taxonomies'][] = $taxonomy;
                $cache->set(
                    'migrate-from-wordpress-available-taxonomy-types',
                    ArrayHelper::getColumn($variables['taxonomies'], 'value'),
                    0,
                    new TagDependency(['tags' => 'migrate-from-wordpress'])
                );
                // Post types
                $variables['postTypes'] = [];
                $postType = [];
                $postType['value'] = '';
                $postType['label'] = 'posts';
                $variables['postTypes'][] = $postType;
                $cache->set(
                    'migrate-from-wordpress-available-post-types',
                    ArrayHelper::getColumn($variables['postTypes'], 'value'),
                    0,
                    new TagDependency(['tags' => 'migrate-from-wordpress'])
                );
                // Page types
                $variables['pageTypes'] = [];
                $pageType = [];
                $pageType['value'] = '';
                $pageType['label'] = 'pages';
                $variables['pageTypes'][] = $pageType;
                $cache->set(
                    'migrate-from-wordpress-available-page-types',
                    ArrayHelper::getColumn($variables['pageTypes'], 'value'),
                    0,
                    new TagDependency(['tags' => 'migrate-from-wordpress'])
                );
                return $variables;
            },
            1,
            new TagDependency(['tags' => 'migrate-from-wordpress'])
        );

        $languages = SiteHelper::availableWordPressLanguages();
        $variables['languages'] = $languages;
        $bodyParams = Craft::$app->request->getQueryParams();

        if (isset($bodyParams['lang'])) {
            $variables['selectedLanguage'] = $bodyParams['lang'];
        } else {
            reset($variables['languages']);
            $variables['selectedLanguage'] = key($variables['languages']);
        }

        return $this->renderTemplate('migrate-from-wordpress/_migrate', $variables);
    }

    /**
     * Clear cache by tag dependency or key
     * @return Response
     */
    public function actionClearCache(): Response
    {
        $request = Craft::$app->getRequest();
        $item = $request->getRequiredBodyParam('item');
        $cache = Craft::$app->getCache();
        switch ($item) {
            case 'all':
                TagDependency::invalidate(Yii::$app->cache, MigrateFromWordPressPlugin::$plugin->id);
                break;
            default:
                // Invalidate depended cache
                TagDependency::invalidate(Yii::$app->cache, $item);
                // Delete cache
                $cache->delete($item);
                break;
        }
        Craft::$app->getSession()->setNotice(Craft::t('migrate-from-wordpress', 'Cache was cleared.'));
        return $this->redirectToPostedUrl();
    }

    /**
     * Filter suggested fields based on type, container and item's field layout.
     *
     * @param string $convertTo
     * @param string $fieldContainer
     * @param string|null $limitFieldsToLayout
     * @param string|null $item
     * @param int|null $itemId
     * @return Response
     */
    public function actionFieldsFilter(string $convertTo, string $fieldContainer, string $limitFieldsToLayout = null, string $item = null, int $itemId = null): Response
    {
        $fieldsArray = FieldHelper::findField(null, $convertTo, $fieldContainer, $limitFieldsToLayout, $item, $itemId);
        $fieldsArray = array_merge([['value' => 'new field', 'label' => 'New field']], $fieldsArray);
        return $this->asJson($fieldsArray);
    }

    /**
     * Call to Get tables, matrix and super tables.
     *
     * @param string $containerTypes
     * @param string $limitFieldsToLayout
     * @param string|null $item
     * @param int|null $entryTypeId
     * @param int $onlyContainer to get inside of container like blocks and table fields or not
     * @return Response
     */
    public function actionGetContainerFields(string $containerTypes, string $limitFieldsToLayout, string $item = null, int $entryTypeId = null, int $onlyContainer): Response
    {
        $containers = GeneralHelper::containers($containerTypes, $limitFieldsToLayout, $item, $entryTypeId, $onlyContainer);
        return $this->asJson(
            $containers
        );
    }

    /**
     * Get available entry types.
     *
     * @param int $sectionId
     * @return Response
     */
    public function actionGetEntryTypes(int $sectionId): Response
    {
        $variables['entryType'][] = ['value' => '', 'label' => 'select one'];
        if ($sectionId) {
            foreach (Craft::$app->sections->getEntryTypesBySectionId($sectionId) as $entryType) {
                $entryTypes['value'] = $entryType->id;
                $entryTypes['label'] = $entryType->name;
                $variables['entryType'][] = $entryTypes;
            }
        }
        return $this->asJson($variables['entryType']);
    }

    /**
     * Get matrix's block types.
     *
     * @param string $fieldHandle
     * @return Response
     */
    public function actionGetMatrixBlockTypes(string $fieldHandle): Response
    {
        $matrixBlockTypes = [];
        $field = Craft::$app->fields->getFieldByHandle($fieldHandle);
        if ($field) {
            $blockTypes = Craft::$app->matrix->getBlockTypesByFieldId($field->id);
            foreach ($blockTypes as $blockType) {
                $matrixBlockTypes[] = ['value' => $blockType->handle, 'label' => $blockType->name];
            }
        }
        return $this->asJson(
            $matrixBlockTypes
        );
    }

    /**
     * Get matrix block types's tables
     *
     * @param string $matrixHandle
     * @param string $blockTypeHandle
     * @return Response
     */
    public function actionGetMatrixTables(string $matrixHandle, string $blockTypeHandle): Response
    {
        $tables = [];
        $matrixField = Craft::$app->fields->getFieldByHandle($matrixHandle);
        if ($matrixField) {
            $blockTypes = Craft::$app->matrix->getBlockTypesByFieldId($matrixField->id);
            foreach ($blockTypes as $blockType) {
                if ($blockType->handle == $blockTypeHandle) {
                    $fields = $blockType->getCustomFields();
                    foreach ($fields as $field) {
                        if (get_class($field) == 'craft\fields\Table') {
                            $tables[] = ['value' => $field->handle, 'label' => $field->name];
                        }
                    }
                }
            }
        }
        return $this->asJson(
            $tables
        );
    }
}
