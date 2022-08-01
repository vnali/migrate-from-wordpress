<?php

namespace vnali\migratefromwordpress\items;

use Craft;
use stdClass;
use Symfony\Component\DomCrawler\Crawler;
use vnali\migratefromwordpress\helpers\Curl;
use vnali\migratefromwordpress\helpers\FieldHelper;
use vnali\migratefromwordpress\helpers\GeneralHelper;
use vnali\migratefromwordpress\MigrateFromWordPress as MigrateFromWordPressPlugin;

class NavigationItem
{
    public const MIGRATE_FROM_WORDPRESS = "Migrate from WordPress - ";

    /**
     * @var string|null
     */
    public $_contentLanguage;

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
    private $_navigationItems;

    /**
     * @var string
     */
    private $_navigationId;

    /**
     * Constructor.
     *
     * @param string $navigationId
     * @param int $page
     * @param int $limit
     * @param string $contentLanguage
     */
    public function __construct(string $navigationId, int $page = 1, int $limit = 10, string $contentLanguage = null)
    {
        $this->_navigationId = $navigationId;
        $this->_contentLanguage = $contentLanguage;
        $wordpressURL = MigrateFromWordPressPlugin::$plugin->settings->wordpressURL;
        $wordpressRestApiEndpoint = MigrateFromWordPressPlugin::$plugin->settings->wordpressRestApiEndpoint;
        $separator = '?';
        if (strpos($wordpressRestApiEndpoint, '?rest_route=') === 0) {
            $separator = '&';
        }
        $address = $wordpressURL . '/' . $wordpressRestApiEndpoint . '/navigation/' . $navigationId . $separator . 'per_page=' . $limit . '&page=' . $page;
        $response = Curl::sendToRestAPI($address);
        $response = json_decode($response);
        //
        $blocks = [];
        $crawler = new Crawler($response->content->rendered);
        foreach ($crawler->filter('html body')->children() as $domElement) {
            $html = $domElement->ownerDocument->saveHTML($domElement);
            $blocks[] = $html;
        }
        foreach ($blocks as $block) {
            $crawler = new Crawler($block);
            $c = $crawler->filter('html body a');
            $href = $c->attr('href');
            $c = $crawler->filter('html body span');
            $text = $c->text();
            $navigationItem = new stdClass();
            $navigationItem->url = $href;
            $navigationItem->title = $text;
            $this->_navigationItems[] = $navigationItem;
        }
        //
        $this->_hasNext = false;
    }

    /**
     * Get field definition of navigation items
     *
     * @return array|null
     */
    public function getFieldDefinitions()
    {
        if (isset($this->_navigationItems[0])) {
            $content = [];
            $this->_content($this->_navigationItems[0], $content, 1);
            $this->_fieldDefinitions = FieldHelper::fieldOptions($content, 'navigation-' . $this->_navigationId, '');
        } else {
            $this->_fieldDefinitions = null;
        }
        Craft::$app->cache->set('migrate-from-wordpress-navigation-' . $this->_navigationId . '-fields', json_encode($this->_fieldDefinitions));
        return $this->_fieldDefinitions;
    }

    /**
     * Get values of WordPress navigation items
     *
     * @return array
     */
    public function getValues(): array
    {
        $this->_fieldDefinitions = json_decode(Craft::$app->cache->get('migrate-from-wordpress-navigation-' . $this->_navigationId . '-fields'), true);
        $contents = [];
        foreach ($this->_navigationItems as $navigationItem) {
            $content = null;
            $this->_content($navigationItem, $content, 0);
            if ($content !== null) {
                $contents[] = $content;
            } else {
                $content['fields']['notvalid']['value'] = true;
                $contents[] = $content;
            }
        }
        return array($contents, $this->_hasNext);
    }

    /**
     * Get attributes and relationships of WordPress navigation items
     *
     * @param object $navigationItem
     * @param array $content
     * @param int $gettingFields
     */
    private function _content(object $navigationItem, array &$content = null, int $gettingFields)
    {
        $this->_attributes($navigationItem, $content, $gettingFields);

        if (!$content) {
            return false;
        }
    }

    /**
     * Get attributes of WordPress navigation items
     *
     * @param object $navigationItem
     * @param array $content
     * @param int $gettingFields
     * @return void
     */
    private function _attributes(object $navigationItem, array &$content = null, int $gettingFields)
    {
        if (isset($navigationItem->url)) {
            $content['fields']['wordpressUUID']['value'] = $navigationItem->url;
            $content['fields']['wordpressUUID']['config']['type'] = 'text';
            $content['fields']['wordpressUUID']['config']['label'] = 'WordPress UUID';
            $content['fields']['wordpressNavigationId']['value'] = $this->_navigationId;
            $content['fields']['wordpressNavigationId']['config']['type'] = 'text';
            $content['fields']['wordpressNavigationId']['config']['label'] = 'WordPress navigation Id';
            $content['fields']['link']['config']['type'] = 'text';
            $content['fields']['link']['config']['label'] = 'link';
            if (isset($navigationItem->url)) {
                $content['fields']['wordpressLink']['value'] = $navigationItem->url;
                $content['fields']['wordpressLink']['config']['type'] = 'text';
                $content['fields']['wordpressLink']['config']['label'] = 'WordPress link';
            }
            if ($gettingFields == 0) {
                list($uri, $navType, $navElementId) = GeneralHelper::convertWordPressUri($navigationItem);
                $content['fields']['link']['value'] = $uri;
                $content['fields']['navType']['value'] = $navType;
                $content['fields']['navElementId']['value'] = $navElementId;
            }
        }

        if (isset($navigationItem->title)) {
            $content['fields']['title']['value'] = $navigationItem->title;
        }
    }
}
