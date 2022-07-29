<?php

namespace vnali\migratefromwordpress;

use Craft;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\elements\User;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\feedme\events\FeedProcessEvent;
use craft\feedme\Plugin as FeedmePlugin;
use craft\feedme\records\FeedRecord;
use craft\feedme\services\Process;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\FolderCriteria;
use craft\utilities\ClearCaches;
use craft\web\UrlManager;

use verbb\navigation\elements\Node;

use vnali\migratefromwordpress\MigrateFromWordPress as MigrateFromWordPressPlugin;
use vnali\migratefromwordpress\models\Settings;

use Yii;
use yii\base\Event;
use yii\caching\TagDependency;
use yii\web\ServerErrorHttpException;

class MigrateFromWordPress extends Plugin
{
    public static $plugin;
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        self::$plugin = $this;

        $this->_initRoutes();
        $this->_initEvents();
    }

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    public function getSettingsResponse(): mixed
    {
        Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('migrate-from-wordpress/settings/plugin'));
        return null;
    }

    private function _initRoutes()
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['migrate-from-wordpress/default/fields-filter'] = 'migrate-from-wordpress/default/fields-filter';
                $event->rules['migrate-from-wordpress/default/get-container-fields'] = 'migrate-from-wordpress/default/get-container-fields';
                $event->rules['migrate-from-wordpress/default/get-container-fields'] = 'migrate-from-wordpress/default/get-container-fields';
                $event->rules['migrate-from-wordpress/default/get-entry-types'] = 'migrate-from-wordpress/default/get-entry-types';
                $event->rules['migrate-from-wordpress/default/get-matrix-block-types'] = 'migrate-from-wordpress/default/get-matrix-block-types';
                $event->rules['migrate-from-wordpress/default/get-matrix-tables'] = 'migrate-from-wordpress/default/get-matrix-tables';
                $event->rules['migrate-from-wordpress/default/index'] = 'migrate-from-wordpress/default/index';
                $event->rules['migrate-from-wordpress/files/migrate'] = 'migrate-from-wordpress/files/migrate';
                $event->rules['migrate-from-wordpress/menus/migrate'] = 'migrate-from-wordpress/menus/migrate';
                $event->rules['migrate-from-wordpress/navigations/migrate'] = 'migrate-from-wordpress/navigations/migrate';
                $event->rules['migrate-from-wordpress/pages/migrate'] = 'migrate-from-wordpress/pages/migrate';
                $event->rules['migrate-from-wordpress/posts/migrate'] = 'migrate-from-wordpress/posts/migrate';
                $event->rules['migrate-from-wordpress/settings/plugin'] = 'migrate-from-wordpress/settings/plugin';
                $event->rules['migrate-from-wordpress/taxonomies/migrate'] = 'migrate-from-wordpress/taxonomies/migrate';
                $event->rules['migrate-from-wordpress/users/migrate'] = 'migrate-from-wordpress/users/migrate';
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['migrate-from-wordpress/files/values'] = 'migrate-from-wordpress/files/values';
                $event->rules['migrate-from-wordpress/menus/values'] = 'migrate-from-wordpress/menus/values';
                $event->rules['migrate-from-wordpress/navigations/values'] = 'migrate-from-wordpress/navigations/values';
                $event->rules['migrate-from-wordpress/pages/values'] = 'migrate-from-wordpress/pages/values';
                $event->rules['migrate-from-wordpress/posts/values'] = 'migrate-from-wordpress/posts/values';
                $event->rules['migrate-from-wordpress/taxonomies/values'] = 'migrate-from-wordpress/taxonomies/values';
                $event->rules['migrate-from-wordpress/users/values'] = 'migrate-from-wordpress/users/values';
            }
        );
    }

    private function _initEvents()
    {
        Event::on(Process::class, Process::EVENT_AFTER_PROCESS_FEED, function (FeedProcessEvent $event) {
            $cache = Craft::$app->getCache();
            $parsedUrl = parse_url($event->feed['feedUrl']);
            $path = $parsedUrl['path'];
            $query = $parsedUrl['query'];
            parse_str($query, $output);

            $contentLanguage = '';
            if (isset($output['contentLanguage'])) {
                $contentLanguage = $output['contentLanguage'];
            }

            switch ($path) {
                case '/migrate-from-wordpress/files/values':
                    $cache->set('migrate-from-wordpress-convert-status-file', 'process', 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));
                    break;
                case '/migrate-from-wordpress/menus/values':
                    $menuType = $output['menuId'];
                    $isUpdateFeed = $output['isUpdateFeed'];

                    if (!$isUpdateFeed) {
                        $cache->set('migrate-from-wordpress-convert-status-menu-' . $contentLanguage . '-' . $menuType, 'process', 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));
                        if (isset($output['hasUpdateFeed']) && $output['hasUpdateFeed'] == 0) {
                            $cache->set('migrate-from-wordpress-convert-status-menu-' . $contentLanguage . '-' . $menuType . '-update', 'process', 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));
                        }
                    } else {
                        $cache->set('migrate-from-wordpress-convert-status-menu-' . $contentLanguage . '-' . $menuType . '-update', 'process', 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));
                    }

                    $siteSettings = Craft::$app->cache->get('migrate-from-wordpress-menu-siteSettings-' . $menuType);
                    $notProcessed = false;

                    foreach ($siteSettings as $key => $siteSetting) {
                        if ($siteSetting['convert'] == '1') {
                            if (Craft::$app->getCache()->get('migrate-from-wordpress-convert-status-menu-' . $key . '-' . $menuType) != 'process') {
                                $notProcessed = true;
                                break;
                            }
                            if (Craft::$app->getCache()->get('migrate-from-wordpress-convert-status-menu-' . $key . '-' . $menuType . '-update') != 'process') {
                                $notProcessed = true;
                                break;
                            }
                        }
                    }

                    if (!$notProcessed) {
                        Craft::$app->getCache()->set('migrate-from-wordpress-convert-status-menu-' . $menuType, 'process', 0, new TagDependency(['tags' => 'migrate-from-wordpress']));
                    }
                    break;
                case '/migrate-from-wordpress/navigations/values':
                    $navigationType = $output['navigationId'];
                    $cache->set('migrate-from-wordpress-convert-status-navigation-' . $contentLanguage . '-' . $navigationType, 'process', 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));
                    $cache->set('migrate-from-wordpress-convert-status-navigation-' . $contentLanguage . '-' . $navigationType . '-update', 'process', 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));

                    $siteSettings = Craft::$app->cache->get('migrate-from-wordpress-navigation-siteSettings-' . $navigationType);
                    $notProcessed = false;

                    foreach ($siteSettings as $key => $siteSetting) {
                        if ($siteSetting['convert'] == '1') {
                            if (Craft::$app->getCache()->get('migrate-from-wordpress-convert-status-navigation-' . $key . '-' . $navigationType) != 'process') {
                                $notProcessed = true;
                                break;
                            }
                        }
                    }

                    if (!$notProcessed) {
                        Craft::$app->getCache()->set('migrate-from-wordpress-convert-status-navigation-' . $navigationType, 'process', 0, new TagDependency(['tags' => 'migrate-from-wordpress']));
                    }
                    break;
                case '/migrate-from-wordpress/pages/values':
                    $pageType = $output['pageType'];
                    $isUpdateFeed = $output['isUpdateFeed'];

                    if (!$isUpdateFeed) {
                        $cache->set('migrate-from-wordpress-convert-status-page-' . $contentLanguage . '-' . $pageType, 'process', 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));
                        if (isset($output['hasUpdateFeed']) && $output['hasUpdateFeed'] == 0) {
                            $cache->set('migrate-from-wordpress-convert-status-page-' . $contentLanguage . '-' . $pageType . '-update', 'process', 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));
                        }
                    } else {
                        $cache->set('migrate-from-wordpress-convert-status-page-' . $contentLanguage . '-' . $pageType . '-update', 'process', 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));
                    }

                    $siteSettings = Craft::$app->cache->get('migrate-from-wordpress-page-siteSettings-' . $pageType);
                    $notProcessed = false;

                    foreach ($siteSettings as $key => $siteSetting) {
                        if ($siteSetting['convert'] == '1') {
                            if (Craft::$app->getCache()->get('migrate-from-wordpress-convert-status-page-' . $key . '-' . $pageType) != 'process') {
                                $notProcessed = true;
                                break;
                            }
                            if (Craft::$app->getCache()->get('migrate-from-wordpress-convert-status-page-' . $key . '-' . $pageType . '-update') != 'process') {
                                $notProcessed = true;
                                break;
                            }
                        }
                    }

                    if (!$notProcessed) {
                        Craft::$app->getCache()->set('migrate-from-wordpress-convert-status-page-' . $pageType, 'process', 0, new TagDependency(['tags' => 'migrate-from-wordpress']));
                    }
                    break;
                case '/migrate-from-wordpress/posts/values':
                    $postType = $output['postType'];
                    $isUpdateFeed = $output['isUpdateFeed'];

                    if (!$isUpdateFeed) {
                        $cache->set('migrate-from-wordpress-convert-status-post-' . $contentLanguage . '-' . $postType, 'process', 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));
                        if (isset($output['hasUpdateFeed']) && $output['hasUpdateFeed'] == 0) {
                            $cache->set('migrate-from-wordpress-convert-status-post-' . $contentLanguage . '-' . $postType . '-update', 'process', 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));
                        }
                    } else {
                        $cache->set('migrate-from-wordpress-convert-status-post-' . $contentLanguage . '-' . $postType . '-update', 'process', 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));
                    }

                    $siteSettings = Craft::$app->cache->get('migrate-from-wordpress-post-siteSettings-' . $postType);
                    $notProcessed = false;

                    foreach ($siteSettings as $key => $siteSetting) {
                        if ($siteSetting['convert'] == '1') {
                            if (Craft::$app->getCache()->get('migrate-from-wordpress-convert-status-post-' . $key . '-' . $postType) != 'process') {
                                $notProcessed = true;
                                break;
                            }
                            if (Craft::$app->getCache()->get('migrate-from-wordpress-convert-status-post-' . $key . '-' . $postType . '-update') != 'process') {
                                $notProcessed = true;
                                break;
                            }
                        }
                    }

                    if (!$notProcessed) {
                        Craft::$app->getCache()->set('migrate-from-wordpress-convert-status-post-' . $postType, 'process', 0, new TagDependency(['tags' => 'migrate-from-wordpress']));
                    }
                    break;
                case '/migrate-from-wordpress/users/values':
                    $cache->set('migrate-from-wordpress-convert-status-user', 'process', 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));
                    break;
                case '/migrate-from-wordpress/taxonomies/values':
                    $vocabId = $output['taxonomyId'];
                    $isUpdateFeed = $output['isUpdateFeed'];

                    if (!$isUpdateFeed) {
                        $cache->set('migrate-from-wordpress-convert-status-taxonomy-' . $contentLanguage . '-' . $vocabId, 'process', 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));
                        if (isset($output['hasUpdateFeed']) && $output['hasUpdateFeed'] == 0) {
                            $cache->set('migrate-from-wordpress-convert-status-taxonomy-' . $contentLanguage . '-' . $vocabId . '-update', 'process', 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));
                        }
                    } else {
                        $cache->set('migrate-from-wordpress-convert-status-taxonomy-' . $contentLanguage . '-' . $vocabId . '-update', 'process', 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));
                    }

                    $siteSettings = Craft::$app->cache->get('migrate-from-wordpress-taxonomy-siteSettings-' . $vocabId);
                    $notProcessed = false;

                    foreach ($siteSettings as $key => $siteSetting) {
                        if ($siteSetting['convert'] == '1') {
                            if (Craft::$app->getCache()->get('migrate-from-wordpress-convert-status-taxonomy-' . $key . '-' . $vocabId) != 'process') {
                                $notProcessed = true;
                                break;
                            }
                            if (Craft::$app->getCache()->get('migrate-from-wordpress-convert-status-taxonomy-' . $key . '-' . $vocabId . '-update') != 'process') {
                                $notProcessed = true;
                                break;
                            }
                        }
                    }

                    if (!$notProcessed) {
                        Craft::$app->getCache()->set('migrate-from-wordpress-convert-status-taxonomy-' . $vocabId, 'process', 0, new TagDependency(['tags' => 'migrate-from-wordpress']));
                    }
                    break;
                default:
                    break;
            }
        });

        Event::on(Process::class, Process::EVENT_STEP_BEFORE_ELEMENT_MATCH, function (FeedProcessEvent $event) {
            if (MigrateFromWordpressPlugin::$plugin->settings->fetchFilesByAssetIndex) {
                // When we want to update existing files -imported via asset index- and we check unique asset id
                $parsedUrl = parse_url($event->feed['feedUrl']);
                $query = $parsedUrl['query'];
                parse_str($query, $output);
                if (isset($output['volumeId'])) {
                    $volumeId = $output['volumeId'];
                    if ($event->feed['elementType'] == 'craft\elements\Asset') {
                        $filename = $event->feedData['filename/value'];
                        $assetsService = Craft::$app->getAssets();
                        $criteria = new FolderCriteria();
                        $criteria->volumeId = $volumeId;
                        if ($event->feedData['folder/value']) {
                            $folderPath = $event->feedData['folder/value'] . '/';
                        } else {
                            $folderPath = null;
                        }
                        $criteria->path = $folderPath;
                        $folder = $assetsService->findFolder($criteria);
                        if (isset($folder)) {
                            $asset = Asset::find()->filename($filename)->folderId($folder->id)->one();
                            if ($asset) {
                                $event->feedData['AssetId/value'] = $asset->id;
                                $event->contentData['id'] = $asset->id;
                            }
                        } else {
                            throw new ServerErrorHttpException('Make sure you copied content of wp-content/uploads from WordPress');
                        }
                    }
                }
            }
        });

        Event::on(Process::class, Process::EVENT_STEP_BEFORE_ELEMENT_SAVE, function (FeedProcessEvent $event) {
            $parsedUrl = parse_url($event->feed['feedUrl']);
            $path = $parsedUrl['path'];
            $query = $parsedUrl['query'];
            parse_str($query, $output);

            if ($event->feed['elementType'] == User::class) {
                // Protect the user 1 and who runs feed-me anyway from password change
                if ($event->feedData['wordpressUserId/value'] != '1' && $event->feedData['username/value'] != Craft::$app->getUser()->getIdentity()->username) {
                    $userIsAvailable = $event->element['id'];

                    // If element doesn't exists or password protection is disabled on element update
                    if (!$userIsAvailable) {
                        $event->element['newPassword'] = StringHelper::randomString(8);
                    }
                }
            }

            if ($event->feed['elementType'] == 'verbb\navigation\elements\Node') {
                $navType = $event->feedData['navType/value'];
                $navElementId = $event->feedData['navElementId/value'];

                if ($navType == 'craft\elements\Entry' && $navElementId) {
                    $event->element['elementId'] = $navElementId;
                    $event->element['type'] = 'craft\elements\Entry';
                } elseif ($navType == 'craft\elements\Category' && $navElementId) {
                    $event->element['elementId'] = $navElementId;
                    $event->element['type'] = 'craft\elements\Category';
                } elseif ($navType == 'craft\elements\Asset' && $navElementId) {
                    $event->element['elementId'] = $navElementId;
                    $event->element['type'] = 'craft\elements\Asset';
                } elseif ($navType == 'verbb\navigation\nodetypes\PassiveType') {
                    $event->element['type'] = 'verbb\navigation\nodetypes\PassiveType';
                } else {
                    $event->element['type'] = 'verbb\navigation\nodetypes\CustomType';
                }
            }

            if ($path == '/migrate-from-wordpress/files/values') {
                if (isset($output['volumeId'])) {
                    $volumeId = $output['volumeId'];
                    $cache = Craft::$app->getCache();
                    $postedCondition = json_decode($cache->get('migrate-from-wordpress-file-posted-condition-' . $volumeId), true);
                    if ($postedCondition) {
                        $useAltNativeField = $postedCondition['useAltNativeField'];
                        if ($useAltNativeField && $event->feedData['mediaAlt/value']) {
                            $event->element['alt'] = $event->feedData['mediaAlt/value'];
                        }
                        // Set asset title
                        $titleOption = $postedCondition['titleOption'];
                        switch ($titleOption) {
                            case 'media-alt':
                                if ($event->feedData['mediaAlt/value']) {
                                    $event->element['title'] = $event->feedData['mediaAlt/value'];
                                }
                                break;
                            case 'media-caption':
                                if ($event->feedData['mediaCaption/value']) {
                                    $event->element['title'] = $event->feedData['mediaCaption/value'];
                                }
                                break;
                            case 'media-title':
                                if ($event->feedData['mediaTitle/value']) {
                                    $event->element['title'] = $event->feedData['mediaTitle/value'];
                                }
                                break;
                            default:
                                # code...
                                break;
                        }
                    }
                }

                if (isset($event->feedData['uploaderUUID/value'])) {
                    $uploaderUUID = $event->feedData['uploaderUUID/value'];
                    $userQuery = User::find();
                    $userQuery->wordpressUUID = $uploaderUUID;
                    $user = $userQuery->one();
                    if ($user) {
                        $event->element['uploaderId'] = $user->id;
                    } else {
                        $event->element['uploaderId'] = 1;
                    }
                } else {
                    $event->element['uploaderId'] = 1;
                }
            }
        });

        Event::on(Process::class, Process::EVENT_STEP_BEFORE_ELEMENT_SAVE, function (FeedProcessEvent $event) {
            // Update parent node
            if ($event->feed['elementType'] == 'verbb\navigation\elements\Node') {
                // navigation items has not parent/value
                if (isset($event->feedData['parent/value']) && $event->feedData['parent/value']) {
                    $node = Node::find()->wordpressMenuId($event->feedData['wordpressMenuId/value'])->one();
                    // If node is available
                    if ($node) {
                        $parentNode = Node::find()->wordpressMenuId($event->feedData['parent/value'])->one();
                        // If parent node is available
                        if ($parentNode) {
                            $event->element->setParent($parentNode);
                        }
                    }
                }
            }
        });

        Event::on(
            __CLASS__,
            self::EVENT_BEFORE_SAVE_SETTINGS,
            function ($event) {

                // TODO: move reset function before other plugin setting validation.
                $clearAllCache = MigrateFromWordPressPlugin::$plugin->getSettings()->clearAllCache;
                if ($clearAllCache) {
                    TagDependency::invalidate(Yii::$app->cache, MigrateFromWordPressPlugin::$plugin->id);
                }

                $clearFeedmeLogs = MigrateFromWordPressPlugin::$plugin->getSettings()->clearFeedmeLogs;
                if ($clearFeedmeLogs) {
                    FeedmePlugin::$plugin->logs->clear();
                }

                $clearFeedsCreatedByPlugin = MigrateFromWordPressPlugin::$plugin->getSettings()->clearFeedsCreatedByPlugin;
                if ($clearFeedsCreatedByPlugin) {
                    $feedRecords = FeedRecord::find()->all();
                    foreach ($feedRecords as $feedRecord) {
                        /** @var FeedRecord $feedRecord */
                        if (strpos('Migrate from Wordpress - ', $feedRecord->name) == 0) {
                            $feedRecord->delete();
                        }
                    }
                }

                // Don't let bulk delete Craft entities on dev environment
                if (Craft::$app->config->env == 'dev') {
                    $deleteAllCategories = MigrateFromWordPressPlugin::$plugin->getSettings()->deleteAllCategories;
                    if ($deleteAllCategories) {
                        $categories = Craft::$app->categories->getAllGroups();
                        foreach ($categories as $category) {
                            Craft::$app->categories->deleteGroupById($category->id);
                        }
                    }

                    $deleteAllFields = MigrateFromWordPressPlugin::$plugin->getSettings()->deleteAllFields;
                    if ($deleteAllFields) {
                        $fields = Craft::$app->fields->getAllFields();
                        foreach ($fields as $field) {
                            Craft::$app->getFields()->deleteField($field);
                        }
                    }

                    $deleteAllGlobals = MigrateFromWordPressPlugin::$plugin->getSettings()->deleteAllGlobals;
                    if ($deleteAllGlobals) {
                        $setIds = Craft::$app->globals->getAllSetIds();
                        foreach ($setIds as $setId) {
                            Craft::$app->globals->deleteGlobalSetById($setId);
                        }
                    }

                    $deleteAllSections = MigrateFromWordPressPlugin::$plugin->getSettings()->deleteAllSections;
                    if ($deleteAllSections) {
                        $sectionIds = Craft::$app->sections->getAllSectionIds();
                        foreach ($sectionIds as $sectionId) {
                            Craft::$app->sections->deleteSectionById($sectionId);
                        }
                    }

                    $deleteAllTags = MigrateFromWordPressPlugin::$plugin->getSettings()->deleteAllTags;
                    if ($deleteAllTags) {
                        $tagGroupsId = Craft::$app->tags->getAllTagGroupIds();
                        foreach ($tagGroupsId as $tagGroupId) {
                            Craft::$app->tags->deleteTagGroupById($tagGroupId);
                        }
                    }

                    $deleteAllVolumes = MigrateFromWordPressPlugin::$plugin->getSettings()->deleteAllVolumes;
                    if ($deleteAllVolumes) {
                        $volumeIds = Craft::$app->volumes->getAllVolumeIds();
                        foreach ($volumeIds as $volumeId) {
                            Craft::$app->volumes->deleteVolumeById($volumeId);
                        }
                    }
                }

                if (
                    MigrateFromWordPressPlugin::$plugin->getSettings()->wordpressURL !=
                    MigrateFromWordPressPlugin::$plugin->getSettings()->oldWordPressURL
                ) {
                    TagDependency::invalidate(Yii::$app->cache, MigrateFromWordPressPlugin::$plugin->id);
                }

                MigrateFromWordPressPlugin::$plugin->getSettings()->step = 1;
                MigrateFromWordPressPlugin::$plugin->getSettings()->oldWordPressURL = MigrateFromWordPressPlugin::$plugin->getSettings()->wordpressURL;
            }
        );

        Event::on(
            ClearCaches::class,
            ClearCaches::EVENT_REGISTER_TAG_OPTIONS,
            function (RegisterCacheOptionsEvent $event) {
                $event->options = array_merge(
                    $event->options,
                    $this->_customAdminCpTagOptions()
                );
            }
        );
    }

    /**
     * Return cache tag used by plugin
     *
     * @return array
     */
    private function _customAdminCpTagOptions(): array
    {
        return [
            [
                'tag' => 'migrate-from-wordpress',
                'label' => Craft::t('migrate-from-wordpress', 'migrate from wordpress'),
            ],
        ];
    }
}
