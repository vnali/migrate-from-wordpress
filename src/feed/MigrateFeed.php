<?php

namespace vnali\migratefromwordpress\feed;

use Craft;

use craft\feedme\Plugin as FeedmePlugin;
use craft\helpers\StringHelper;

use vnali\migratefromwordpress\helpers\SiteHelper;
use vnali\migratefromwordpress\MigrateFromWordPress as MigrateFromWordPressPlugin;

use yii\web\ServerErrorHttpException;

class MigrateFeed
{
    public const MIGRATE_FROM_WORDPRESS = "Migrate from WordPress - ";

    /**
     * @var int|null
     */
    public $categoryId;

    /**
     * @var int|null
     */
    public $entryTypeId;

    /**
     * @var array
     */
    public $fieldMappings;

    /**
     * @var array
     */
    public $fieldMappingsExtra;

    /**
     * @var string
     */
    public $itemType;

    /**
     * @var int|null
     */
    public $navigationId;

    /**
     * @var array
     */
    public $siteSettings;

    /**
     * @var int|null
     */
    public $sectionId;

    /**
     * @var int|null
     */
    public $tagId;

    /**
     * @var string
     */
    public $typeId;

    /**
     * @var int|null
     */
    public $vocabId;

    /**
     * @var int|null
     */
    public $volumeId;

    /**
     * Secret token, which prevents unauthorized access to feed values
     * @var string
     */
    private $_secret;

    public function __construct()
    {
        $this->_secret = Craft::$app->getCache()->get('migrate-from-wordpress-protect-feed-values');
        if (!$this->_secret) {
            $this->_secret = Craft::$app->security->generateRandomString();
            Craft::$app->cache->set('migrate-from-wordpress-protect-feed-values', $this->_secret);
        }
    }

    /**
     * Create feeds
     */
    public function createFeed()
    {
        $secret = $this->_secret;

        // Get Label
        $label = $this->typeId;
        if (!$label) {
            $label = 'Default';
        } else {
            $availableMenuTypes = Craft::$app->cache->get('migrate-from-wordpress-available-menu-types');
            if ($availableMenuTypes && isset($availableMenuTypes[$label]['label'])) {
                $label = $availableMenuTypes[$label]['label'];
            }
        }

        $i = 0;

        $siteSettings = $this->siteSettings;
        $wordpressSites = SiteHelper::availableWordPressLanguages();
        reset($siteSettings);

        foreach ($siteSettings as $key => $siteSetting) {
            if ($siteSetting['convert']) {
                if ($i == 0) {
                    $duplicateHandle = ['add', 'update'];
                    if ($this->itemType == 'post' || $this->itemType == 'page') {
                        $fieldUniques = ['wordpressPostId'];
                    } elseif ($this->itemType == 'taxonomy') {
                        $fieldUniques = ['wordpressLink'];
                    } elseif ($this->itemType == 'menu') {
                        $fieldUniques = ['wordpressMenuId'];
                    } elseif ($this->itemType == 'asset') {
                        $fieldUniques = ['wordpressFileId'];
                    } else {
                        $fieldUniques = ['title'];
                    }
                    $i++;
                } else {
                    $duplicateHandle = ['add', 'update'];
                    if ($this->itemType == 'post' || $this->itemType == 'page') {
                        $fieldUniques = ['wordpressPostId'];
                    } elseif ($this->itemType == 'taxonomy') {
                        $fieldUniques = ['wordpressLink'];
                    } elseif ($this->itemType == 'menu') {
                        $fieldUniques = ['wordpressMenuId'];
                    } elseif ($this->itemType == 'block') {
                        $fieldUniques = ['wordpressBlockId'];
                    }
                }

                $to = '';
                if ($this->entryTypeId) {
                    $to = 'entry';
                } elseif ($this->tagId) {
                    $to = 'tag';
                } elseif ($this->categoryId) {
                    $to = 'category';
                } elseif ($this->volumeId) {
                    $to = 'asset';
                }

                $wordpressURL = MigrateFromWordPressPlugin::$plugin->settings->wordpressURL;
                $model = new \craft\feedme\models\FeedModel();
                $limit = MigrateFromWordPressPlugin::$plugin->settings->limit;
                $isUpdateFeed = "&isUpdateFeed=0";

                $feedName = '';
                $feedUrl = '';

                if ($this->itemType == 'post') {
                    // Get exact name of column uuid
                    $uuidField = Craft::$app->fields->getFieldByHandle('wordpressUUID');
                    $uuidField = 'field_wordpressUUID_' . $uuidField->columnSuffix;
                    //
                    $this->fieldMappings['parent']['options']['match'] = $uuidField;
                    $this->fieldMappings['authorId']['options']['match'] = $uuidField;

                    $hasUpdateFeed = "&hasUpdateFeed=1";
                    $feedName = self::MIGRATE_FROM_WORDPRESS . "migrate '$label' post to $to from " . $wordpressURL . ' - ' . $wordpressSites[$key]['label'] . " language";
                    $feedUrl = Craft::getAlias('@web') . "/migrate-from-wordpress/posts/values?token=$secret&postType=" . $this->typeId .
                        "&contentLanguage=" . $key . "&limit=" . $limit . $isUpdateFeed . $hasUpdateFeed;
                } elseif ($this->itemType == 'page') {
                    // Get exact name of column uuid
                    $uuidField = Craft::$app->fields->getFieldByHandle('wordpressUUID');
                    $uuidField = 'field_wordpressUUID_' . $uuidField->columnSuffix;
                    //
                    $this->fieldMappings['parent']['options']['match'] = $uuidField;
                    $this->fieldMappings['authorId']['options']['match'] = $uuidField;

                    $hasUpdateFeed = "&hasUpdateFeed=1";
                    $feedName = self::MIGRATE_FROM_WORDPRESS . "migrate '$label' page to $to from " . $wordpressURL . ' - ' . $wordpressSites[$key]['label'] . " language";
                    $feedUrl = Craft::getAlias('@web') . "/migrate-from-wordpress/pages/values?token=$secret&pageType=" . $this->typeId .
                        "&contentLanguage=" . $key . "&limit=" . $limit . $isUpdateFeed . $hasUpdateFeed;
                } elseif ($this->itemType == 'taxonomy') {
                    $hasUpdateFeed = "&hasUpdateFeed=0";
                    $feedName = self::MIGRATE_FROM_WORDPRESS . "migrate '$label' taxonomy to $to from " . $wordpressURL . ' - ' . $wordpressSites[$key]['label'] . " language";
                    $feedUrl = Craft::getAlias('@web') . "/migrate-from-wordpress/taxonomies/values?token=$secret&taxonomyId=" . $this->typeId . "&contentLanguage=" . $key . "&limit=" . $limit . $isUpdateFeed . $hasUpdateFeed;
                } elseif ($this->itemType == 'menu') {
                    $feedName = self::MIGRATE_FROM_WORDPRESS . "migrate '$label' menu to $to from " . $wordpressURL . ' - ' . $wordpressSites[$key]['label'] . " language";
                    $feedUrl = Craft::getAlias('@web') . "/migrate-from-wordpress/menus/values?token=$secret&menuId=" . $this->typeId . "&contentLanguage=" . $key . "&limit=" . $limit . $isUpdateFeed;
                }

                $model->name = $feedName;
                $model->feedUrl = $feedUrl;
                $model->feedType = 'json';
                $model->primaryElement = 'data';

                if ($this->entryTypeId) {
                    $model->elementType = 'craft\elements\Entry';
                    $model->elementGroup['craft\elements\Entry']['section'] = $this->sectionId;
                    $model->elementGroup['craft\elements\Entry']['entryType'] = $this->entryTypeId;
                } elseif ($this->tagId) {
                    $model->elementType = 'craft\elements\Tag';
                    $model->elementGroup['craft\elements\Tag'] = $this->tagId;
                } elseif ($this->categoryId) {
                    $model->elementType = 'craft\elements\Category';
                    $model->elementGroup['craft\elements\Category'] = $this->categoryId;
                } elseif ($this->volumeId) {
                    $elementType = 'craft\elements\Asset';
                    $model->elementType = $elementType;
                    $model->elementGroup['craft\elements\Asset'] = $this->volumeId;
                } elseif ($this->navigationId) {
                    $elementType = 'verbb\navigation\elements\Node';
                    $model->elementType = $elementType;
                    $model->elementGroup['verbb\navigation\elements\Node'] = $this->navigationId;
                }
                $model->backup = true;
                $model->duplicateHandle = $duplicateHandle;
                $model->passkey = StringHelper::randomString(10);
                $model->fieldMapping = $this->fieldMappings;
                if (isset($fieldUniques) && is_array($fieldUniques)) {
                    foreach ($fieldUniques as $fieldUnique) {
                        $model->fieldUnique[$fieldUnique] = 1;
                    }
                }
                $model->siteId = $siteSetting['convertTo'];
                $model->paginationNode = 'next';
                if (!FeedmePlugin::$plugin->feeds->savefeed($model)) {
                    Craft::error('feed couldn\'t save.' . '-' . json_encode($model->getErrors()), __METHOD__);
                    throw new ServerErrorHttpException('feed couldn\'t save.' . '-' . json_encode($model->getErrors()));
                }

                //update
                $isUpdateFeed = "&isUpdateFeed=1";
                if ($this->itemType == 'post') {
                    $feedUrl = Craft::getAlias('@web') . "/migrate-from-wordpress/posts/values?token=$secret&postType=" . $this->typeId . "&contentLanguage=" . $key . "&limit=" . $limit . $isUpdateFeed;
                    $fieldUniques = ['wordpressPostId', 'wordpressSiteId'];
                    $feedName = self::MIGRATE_FROM_WORDPRESS . "update parent of '$label' post to $to for " . $wordpressURL . ' - ' . $wordpressSites[$key]['label'] . " language";
                } elseif ($this->itemType == 'page') {
                    $feedUrl = Craft::getAlias('@web') . "/migrate-from-wordpress/pages/values?token=$secret&pageType=" . $this->typeId . "&contentLanguage=" . $key . "&limit=" . $limit . $isUpdateFeed;
                    $fieldUniques = ['wordpressPostId', 'wordpressSiteId'];
                    $feedName = self::MIGRATE_FROM_WORDPRESS . "update parent of '$label' post to $to for " . $wordpressURL . ' - ' . $wordpressSites[$key]['label'] . " language";
                } elseif ($this->itemType == 'taxonomy') {
                    if ($this->tagId) {
                        continue;
                    }
                    $feedUrl = Craft::getAlias('@web') . "/migrate-from-wordpress/taxonomies/values?token=$secret&taxonomyId=" . $this->typeId . "&contentLanguage=" . $key . "&limit=" . $limit . $isUpdateFeed;
                    $fieldUniques = ['wordpressTermId'];
                    $feedName = self::MIGRATE_FROM_WORDPRESS . "update parent of '$label' vocabulary to $to for " . $wordpressURL . ' - ' . $wordpressSites[$key]['label'] . " language";
                } elseif ($this->itemType == 'menu') {
                    $feedUrl = Craft::getAlias('@web') . "/migrate-from-wordpress/menus/values?token=$secret&menuId=" . $this->typeId . "&contentLanguage=" . $key . "&limit=" . $limit . $isUpdateFeed;
                    $fieldUniques = ['wordpressMenuId'];
                    $feedName = self::MIGRATE_FROM_WORDPRESS . "update parent of '$label' menu to $to for " . $wordpressURL . ' - ' . $wordpressSites[$key]['label'] . " language";
                }

                $duplicateHandle = 'update';

                // Feed for update
                $model = new \craft\feedme\models\FeedModel();
                $model->name = $feedName;
                $model->feedUrl = $feedUrl;
                $model->feedType = 'json';
                $model->primaryElement = 'data';
                if ($this->entryTypeId) {
                    $model->elementType = 'craft\elements\Entry';
                    $model->elementGroup['craft\elements\Entry']['section'] = $this->sectionId;
                    $model->elementGroup['craft\elements\Entry']['entryType'] = $this->entryTypeId;
                } elseif ($this->tagId) {
                    $model->elementType = 'craft\elements\Tag';
                    $model->elementGroup['craft\elements\Tag'] = $this->tagId;
                } elseif ($this->categoryId) {
                    $model->elementType = 'craft\elements\Category';
                    $model->elementGroup['craft\elements\Category'] = $this->categoryId;
                } elseif ($this->volumeId) {
                    $elementType = 'craft\elements\Asset';
                    $model->elementType = $elementType;
                    $model->elementGroup['craft\elements\Asset'] = $this->volumeId;
                } elseif ($this->navigationId) {
                    $elementType = 'verbb\navigation\elements\Node';
                    $model->elementType = $elementType;
                    $model->elementGroup['verbb\navigation\elements\Node'] = $this->navigationId;
                }
                $model->primaryElement = 'data';
                $model->backup = true;
                $model->duplicateHandle = [$duplicateHandle];
                if (isset($fieldUniques) && is_array($fieldUniques)) {
                    foreach ($fieldUniques as $fieldUnique) {
                        $model->fieldUnique[$fieldUnique] = 1;
                    }
                }
                $model->passkey = StringHelper::randomString(10);
                $model->fieldMapping = $this->fieldMappingsExtra;
                $model->siteId = $siteSetting['convertTo'];
                $model->paginationNode = 'next';
                if (!FeedmePlugin::$plugin->feeds->savefeed($model)) {
                    throw new ServerErrorHttpException('Feed cannot be saved:' . json_encode($model->getErrors()));
                }
            }
        }
    }
}
