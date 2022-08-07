<?php

namespace vnali\migratefromwordpress\items;

use Craft;
use vnali\migratefromwordpress\helpers\Curl;
use vnali\migratefromwordpress\helpers\FieldHelper;

use vnali\migratefromwordpress\helpers\GeneralHelper;
use vnali\migratefromwordpress\MigrateFromWordPress as MigrateFromWordPressPlugin;
use yii\caching\TagDependency;
use yii\web\ServerErrorHttpException;

class TaxonomyItem
{
    public const MIGRATE_FROM_WORDPRESS = "Migrate from WordPress - ";

    /**
     * @var string|null
     */
    private $_contentLanguage;

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
    private $_taxonomyItems;

    /**
     * @var string
     */
    private $_taxonomyId;

    /**
     * @var string
     */
    private $_restApiAddress;


    /**
     * Constructor
     *
     * @param string|null $taxonomyId
     * @param int $page
     * @param int $limit
     * @param string|null $contentLanguage
     */
    public function __construct(?string $taxonomyId, int $page, int $limit = 1, string $contentLanguage = null)
    {
        if (!in_array($taxonomyId, ['categories', 'tags'])) {
            throw new ServerErrorHttpException('taxonomy is not valid ' . $taxonomyId);
        }
        $this->_taxonomyId = $taxonomyId;
        $this->_contentLanguage = $contentLanguage;
        if (!$this->_contentLanguage) {
            $wordpressURL = MigrateFromWordPressPlugin::$plugin->settings->wordpressURL;
        } else {
            $wordpressURL = MigrateFromWordPressPlugin::$plugin->settings->wordpressLanguageSettings[$contentLanguage]['wordpressURL'];
        }
        $wordpressRestApiEndpoint = MigrateFromWordPressPlugin::$plugin->settings->wordpressRestApiEndpoint;
        $separator = '?';
        if (strpos($wordpressRestApiEndpoint, '?rest_route=') === 0) {
            $separator = '&';
        }
        $this->_restApiAddress = $wordpressURL . '/' . $wordpressRestApiEndpoint;
        $address = $this->_restApiAddress . '/' . $taxonomyId . $separator . 'per_page=' . $limit . '&page=' . $page;
        $response = Curl::sendToRestAPI($address);
        $response = json_decode($response);
        $this->_taxonomyItems = $response;
        // Check if there is next item
        $page = $page + 1;
        $address = $this->_restApiAddress . '/' . $taxonomyId . $separator . 'per_page=' . $limit . '&page=' . $page;
        $response = Curl::sendToRestAPI($address);
        $response = json_decode($response);
        if (is_array($response) && isset($response[0]->id)) {
            $this->_hasNext = true;
        }
    }

    public function getFieldDefinitions()
    {
        if (isset($this->_taxonomyItems[0])) {
            $content = [];
            $this->_content($this->_taxonomyItems[0], $content, 1);
            $this->_fieldDefinitions = FieldHelper::fieldOptions($content, 'taxonomy-' . $this->_taxonomyId, '');
        } else {
            $this->_fieldDefinitions = null;
        }
        Craft::$app->cache->set('migrate-from-wordpress-taxonomy-' . $this->_taxonomyId . '-fields', json_encode($this->_fieldDefinitions), 0, new TagDependency(['tags' => 'migrate-from-wordpress']));
        return $this->_fieldDefinitions;
    }

    /**
     * Get values of WordPress taxonomy items
     *
     * @return array
     */
    public function getValues(): array
    {
        $this->_fieldDefinitions = json_decode(Craft::$app->cache->get('migrate-from-wordpress-taxonomy-' . $this->_taxonomyId . '-fields'), true);
        $contents = [];
        foreach ($this->_taxonomyItems as $taxonomyItem) {
            $content = null;
            $this->_content($taxonomyItem, $content, 0);
            if ($content !== null) {
                $contents[] = $content;
            }
        }

        return array($contents, $this->_hasNext);
    }

    /**
     * Get attributes and relationships of WordPress taxonomy items
     *
     * @param object $taxonomyItem
     * @param array $content
     * @param int $gettingFields
     */
    protected function _content(object $taxonomyItem, array &$content = null, int $gettingFields)
    {
        $this->attributes($taxonomyItem, $content, $gettingFields);

        if (!$content) {
            return false;
        }
    }

    /**
     * Get attributes of WordPress taxonomy items
     *
     * @param object $taxonomyItem
     * @param array $content
     * @param int $gettingFields
     * @return void
     */
    protected function attributes(object $taxonomyItem, array &$content = null, int $gettingFields)
    {
        if (isset($taxonomyItem->id)) {
            if (isset($taxonomyItem->name)) {
                $content['fields']['title']['value'] = $taxonomyItem->name;
            }
            $content['fields']['wordpressTermId']['value'] = $taxonomyItem->id;
            $content['fields']['wordpressTermId']['config']['type'] = 'text';
            $content['fields']['wordpressTermId']['config']['label'] = 'WordPress Term Id';
            $content['fields']['wordpressSiteId']['value'] = MigrateFromWordPressPlugin::$plugin->settings->wordpressURL;
            $content['fields']['wordpressSiteId']['config']['type'] = 'text';
            $content['fields']['wordpressSiteId']['config']['label'] = 'WordPress Site ID';
            if (isset($taxonomyItem->link)) {
                $content['fields']['wordpressUUID']['value'] = $taxonomyItem->link;
                $content['fields']['wordpressUUID']['config']['type'] = 'text';
                $content['fields']['wordpressUUID']['config']['label'] = 'WordPress UUID';
                $content['fields']['wordpressLink']['value'] = $taxonomyItem->link;
                $content['fields']['wordpressLink']['config']['type'] = 'text';
                $content['fields']['wordpressLink']['config']['label'] = 'WordPress link';
            }
            if (isset($taxonomyItem->description)) {
                $content['fields']['description']['value'] = $taxonomyItem->description;
                $content['fields']['description']['config']['type'] = 'text';
                $content['fields']['description']['config']['label'] = 'Taxonomy Description';
            }
            // TODO: get default status of taxonomy from plugin settings
            $content['fields']['status']['value'] = 1;
            $content['fields']['status']['config']['isAttribute'] = true;
            if (isset($taxonomyItem->parent) && $taxonomyItem->parent) {
                $response = Curl::sendToRestAPI($this->_restApiAddress . '/categories/' . $taxonomyItem->parent);
                $response = json_decode($response);
                if (isset($response->link)) {
                    $content['fields']['termParent']['value'] = $response->link;
                    $content['fields']['termParent']['config']['isAttribute'] = true;
                }
            }
            if (isset($taxonomyItem->acf) && $taxonomyItem->acf) {
                $content = GeneralHelper::analyzeACF($taxonomyItem, $content);
            }
        }
    }

    /**
     * Return taxonomy items
     *
     * @return array
     */
    public function getTaxonomyItems(): array
    {
        return $this->_taxonomyItems;
    }
}
