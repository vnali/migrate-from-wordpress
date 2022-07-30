<?php

namespace vnali\migratefromwordpress\items;

use Craft;
use craft\feedme\Plugin as FeedmePlugin;
use craft\helpers\StringHelper;

use vnali\migratefromwordpress\helpers\Curl;
use vnali\migratefromwordpress\helpers\FieldHelper;
use vnali\migratefromwordpress\helpers\GeneralHelper;
use vnali\migratefromwordpress\helpers\SiteHelper;
use vnali\migratefromwordpress\MigrateFromWordPress as MigrateFromWordPressPlugin;

use yii\caching\TagDependency;
use yii\web\ServerErrorHttpException;

class FileItem
{
    public const MIGRATE_FROM_WORDPRESS = "Migrate from WordPress - ";

    /**
     * @var array|null
     */
    private $_fieldDefinitions;

    /**
     * @var bool
     */
    private $_hasNext = false;

    /**
     * @var array
     */
    private $_fileItems;

    /**
     * Constructor.
     *
     * @param int $page
     * @param int $limit
     */
    public function __construct(int $page = 1, int $limit = 10)
    {
        $wordpressURL = MigrateFromWordPressPlugin::$plugin->settings->wordpressURL;
        $wordpressRestApiEndpoint = MigrateFromWordPressPlugin::$plugin->settings->wordpressRestApiEndpoint;
        $address = $wordpressURL . '/' . $wordpressRestApiEndpoint . '/media?per_page=' . $limit . '&page=' . $page;
        $response = Curl::sendToRestAPI($address);
        $response = json_decode($response);
        $this->_fileItems = $response;
        // Check if there is next item
        $page = $page + 1;
        $address = $wordpressURL . '/' . $wordpressRestApiEndpoint . '/media?per_page=' . $limit . '&page=' . $page;
        $response = Curl::sendToRestAPI($address);
        $response = json_decode($response);
        if (is_array($response) && isset($response[0]->id)) {
            $this->_hasNext = true;
        }
    }

    /**
     * Get field definition of block items
     *
     * @return array|null
     */
    public function getFieldDefinitions()
    {
        if (isset($this->_fileItems[0])) {
            $content = [];
            $this->_content($this->_fileItems[0], $content, 1);
            $this->_fieldDefinitions = FieldHelper::fieldOptions($content, 'file', '');
        } else {
            $this->_fieldDefinitions = null;
        }
        Craft::$app->cache->set('migrate-from-wordpress-file-fields', json_encode($this->_fieldDefinitions));
        return $this->_fieldDefinitions;
    }

    /**
     * Get values of WordPress file items
     *
     * @return array
     */
    public function getValues(): array
    {
        $contents = [];
        foreach ($this->_fileItems as $fileItem) {
            $content = null;
            $this->_content($fileItem, $content, 0);
            if ($content !== null) {
                $contents[] = $content;
            }
        }
        return array($contents, $this->_hasNext);
    }

    /**
     * Get attributes and relationships of WordPress file items
     *
     * @param object $fileItem
     * @param array|null $content
     * @param int $gettingFields
     * @return void
     */
    private function _content(object $fileItem, array &$content = null, int $gettingFields)
    {
        $this->_attributes($fileItem, $content, $gettingFields);
    }

    /**
     * Get attributes of WordPress media items
     *
     * @param object $fileItem
     * @param array|null $content
     * @param int $gettingFields
     * @return void
     */
    private function _attributes(object $fileItem, array &$content = null, int $gettingFields)
    {
        if (isset($fileItem->id) && isset($fileItem->guid->rendered) && isset($fileItem->source_url)) {
            $url = $fileItem->guid->rendered;
            $content['fields']['wordpressUUID']['value'] = $url;
            $content['fields']['wordpressUUID']['config']['type'] = 'text';
            $content['fields']['wordpressUUID']['config']['label'] = 'uuid';
            $content['fields']['wordpressSiteId']['value'] = MigrateFromWordPressPlugin::$plugin->settings->wordpressURL;
            $content['fields']['wordpressSiteId']['config']['type'] = 'text';
            $content['fields']['wordpressSiteId']['config']['label'] = 'WordPress Site ID';
            $content['fields']['wordpressFileId']['value'] = $fileItem->id;
            $content['fields']['wordpressFileId']['config']['type'] = 'text';
            $content['fields']['wordpressFileId']['config']['label'] = 'WordPress File Id';
            $content['fields']['lang']['value'] = 'en';
            $content['fields']['lang']['config']['type'] = 'text';
            $content['fields']['lang']['config']['label'] = 'Lang';
            $content['fields']['wordpressLink']['value'] = $url;
            $content['fields']['wordpressLink']['config']['type'] = 'text';
            $content['fields']['wordpressLink']['config']['label'] = 'WordPress link';

            // Asset meta
            $content['fields']['mediaAlt']['config']['type'] = 'text';
            $content['fields']['mediaAlt']['config']['label'] = 'Media Alt';
            $content['fields']['mediaAlt']['config']['translatable'] = 'no';
            if (isset($fileItem->alt_text)) {
                $content['fields']['mediaAlt']['value'] = $fileItem->alt_text;
            }

            $content['fields']['mediaTitle']['config']['type'] = 'text';
            $content['fields']['mediaTitle']['config']['label'] = 'Media Title';
            $content['fields']['mediaTitle']['config']['translatable'] = 'no';
            if (isset($fileItem->title->rendered)) {
                $content['fields']['mediaTitle']['value'] = $fileItem->title->rendered;
            } else {
                $content['fields']['mediaTitle']['value'] = null;
            }

            $content['fields']['mediaCaption']['config']['type'] = 'text';
            $content['fields']['mediaCaption']['config']['label'] = 'Media Caption';
            $content['fields']['mediaCaption']['config']['translatable'] = 'no';
            if (isset($fileItem->caption->rendered)) {
                $content['fields']['mediaCaption']['value'] = $fileItem->caption->rendered;
            } else {
                $content['fields']['mediaCaption']['value'] = null;
            }

            if (isset($fileItem->author)) {
                $content['fields']['uploaderUUID']['value'] = $fileItem->author;
            }

            $urlParts = explode(MigrateFromWordPressPlugin::$plugin->settings->wordpressUploadPath, $url);
            $uri = $urlParts[1];

            list($folder, $filename) = GeneralHelper::analyzeWordPressUri($uri);

            $content['fields']['folder']['value'] = $folder;

            $content['fields']['filename']['value'] = $filename;
            $content['fields']['urlOrPath']['value'] = $url;

            if (MigrateFromWordpressPlugin::$plugin->settings->fetchFilesByAssetIndex) {
                $content['fields']['AssetId']['value'] = $url;
            }

            if (isset($fileItem->acf) && $fileItem->acf) {
                $content = GeneralHelper::analyzeACF($fileItem, $content);
            }

            // Save uri in cache for later
            $fileIds = json_decode(Craft::$app->cache->get('migrate-from-wordpress-files-id-and-url'), true);
            $fileIds[$url] = $fileItem->id;
            Craft::$app->cache->set('migrate-from-wordpress-files-id-and-url', json_encode($fileIds), 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));
        } else {
            throw new ServerErrorHttpException('file item without id, guid or source_url property was founded');
        }
    }

    /**
     * Create feed to migrate WordPress files to Craft asset
     *
     * @param array $fieldMappings
     * @param int $volumeId
     * @return void
     * @throws ServerErrorHttpException if there is a problem when create feed
     */
    public static function createFeed(array $fieldMappings, int $volumeId, string $uniqueFileFeed)
    {
        $secret = Craft::$app->cache->get('migrate-from-wordpress-protect-feed-values');
        if (!$secret) {
            $secret = Craft::$app->security->generateRandomString();
            Craft::$app->cache->set('migrate-from-wordpress-protect-feed-values', $secret);
        }

        $fetchFilesByAssetIndex = MigrateFromWordpressPlugin::$plugin->settings->fetchFilesByAssetIndex;
        if ($fetchFilesByAssetIndex) {
            $fieldUniques = ['id'];
            $duplicateHandle = 'update';
        } else {
            $fieldUniques = ['wordpressFileId', 'wordpressSiteId'];
            $duplicateHandle = 'add';
        }

        $wordpressSites = SiteHelper::availableWordPressLanguages();
        reset($wordpressSites);
        $firstLanguage = key($wordpressSites);

        $limit = MigrateFromWordPressPlugin::$plugin->settings->restItemLimit;
        $feedUrl = Craft::getAlias('@web') . "/migrate-from-wordpress/files/values?token=$secret&language=" . $firstLanguage .
            "&volumeId=" . $volumeId . "&uniqueFileFeed=" . $uniqueFileFeed . "&limit=" . $limit;

        $wordpressURL = MigrateFromWordPressPlugin::$plugin->settings->wordpressURL;

        $model = new \craft\feedme\models\FeedModel();
        $feedName = self::MIGRATE_FROM_WORDPRESS . 'migrate WordPress files - ' . $wordpressURL;
        $model->name = $feedName;
        $model->feedUrl = $feedUrl;
        $model->feedType = 'json';
        $elementType = 'craft\elements\Asset';
        $model->elementType = $elementType;
        $model->primaryElement = 'data';
        $model->elementGroup['craft\elements\Asset'] = $volumeId;
        $model->backup = true;
        $model->duplicateHandle = [$duplicateHandle];
        $model->passkey = StringHelper::randomString(10);
        $model->fieldMapping = $fieldMappings;

        foreach ($fieldUniques as $fieldUnique) {
            $model->fieldUnique[$fieldUnique] = 1;
        }
        $craftPrimarySite = Craft::$app->sites->primarySite;
        $model->siteId = $craftPrimarySite->id;
        $model->paginationNode = 'next';
        if (!FeedmePlugin::$plugin->feeds->savefeed($model)) {
            Craft::error('feed model could not save.' . json_encode($model->getErrors()), __METHOD__);
            throw new ServerErrorHttpException('feed model could not save.' . json_encode($model->getErrors()));
        }
    }
}
