<?php

namespace vnali\migratefromwordpress\items;

use Craft;

use vnali\migratefromwordpress\helpers\Curl;
use vnali\migratefromwordpress\helpers\FieldHelper;
use vnali\migratefromwordpress\helpers\GeneralHelper;
use vnali\migratefromwordpress\MigrateFromWordPress as MigrateFromWordPressPlugin;

class MenuItem
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
    private $_menuItems;

    /**
     * @var string
     */
    private $_menuId;

    /**
     * Constructor.
     *
     * @param string $menuId
     * @param int $page
     * @param int $limit
     * @param string $contentLanguage
     */
    public function __construct(string $menuId, int $page = 1, int $limit = 10, string $contentLanguage = null)
    {
        $this->_menuId = $menuId;
        $this->_contentLanguage = $contentLanguage;
        $wordpressURL = MigrateFromWordPressPlugin::$plugin->settings->wordpressURL;
        $wordpressRestApiEndpoint = MigrateFromWordPressPlugin::$plugin->settings->wordpressRestApiEndpoint;
        $address = $wordpressURL . '/' . $wordpressRestApiEndpoint . '/menu-items?menus=' . $menuId . "&per_page=" . $limit . "&page=" . $page;
        $response = Curl::sendToRestAPI($address);
        $response = json_decode($response);
        $this->_menuItems = $response;
        // Check if there is next item
        $page++;
        $address = $wordpressURL . '/' . $wordpressRestApiEndpoint . '/menu-items?menus=' . $menuId . "&per_page=" . $limit . "&page=" . $page;
        $response = Curl::sendToRestAPI($address);
        $response = json_decode($response);
        if (is_array($response) && isset($response[0]->id)) {
            $this->_hasNext = true;
        }
    }

    /**
     * Get field definition of menu items
     *
     * @return array|null
     */
    public function getFieldDefinitions()
    {
        if (isset($this->_menuItems[0])) {
            $content = [];
            $this->_content($this->_menuItems[0], $content, 1);
            $this->_fieldDefinitions = FieldHelper::fieldOptions($content, 'menu-' . $this->_menuId, '');
        } else {
            $this->_fieldDefinitions = null;
        }
        Craft::$app->cache->set('migrate-from-wordpress-menu-' . $this->_menuId . '-fields', json_encode($this->_fieldDefinitions));
        return $this->_fieldDefinitions;
    }

    /**
     * Get values of WordPress menu items
     *
     * @return array
     */
    public function getValues(): array
    {
        $this->_fieldDefinitions = json_decode(Craft::$app->cache->get('migrate-from-wordpress-menu-' . $this->_menuId . '-fields'), true);
        $contents = [];
        foreach ($this->_menuItems as $menuItem) {
            $content = null;
            $this->_content($menuItem, $content, 0);
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
     * Get attributes and relationships of WordPress menu items
     *
     * @param object $menuItem
     * @param array $content
     * @param int $gettingFields
     */
    private function _content(object $menuItem, array &$content = null, int $gettingFields)
    {
        $this->_attributes($menuItem, $content, $gettingFields);

        if (!$content) {
            return false;
        }
    }

    /**
     * Get attributes of WordPress menu items
     *
     * @param object $menuItem
     * @param array $content
     * @param int $gettingFields
     * @return void
     */
    private function _attributes(object $menuItem, array &$content = null, int $gettingFields)
    {
        if (isset($menuItem->id)) {
            $content['fields']['wordpressUUID']['value'] = $menuItem->id;
            $content['fields']['wordpressUUID']['config']['type'] = 'text';
            $content['fields']['wordpressUUID']['config']['label'] = 'WordPress UUID';
            $content['fields']['wordpressMenuId']['value'] = $menuItem->id;
            $content['fields']['wordpressMenuId']['config']['type'] = 'text';
            $content['fields']['wordpressMenuId']['config']['label'] = 'WordPress menu Id';
            $content['fields']['lang']['value'] = 'en';
            $content['fields']['lang']['config']['type'] = 'text';
            $content['fields']['lang']['config']['label'] = 'Lang';
            $content['fields']['link']['config']['type'] = 'text';
            $content['fields']['link']['config']['label'] = 'link';
            if (isset($menuItem->url)) {
                $content['fields']['wordpressLink']['value'] = $menuItem->url;
                $content['fields']['wordpressLink']['config']['type'] = 'text';
                $content['fields']['wordpressLink']['config']['label'] = 'WordPress link';
            }
            if ($gettingFields == 0) {
                list($uri, $navType, $navElementId) = GeneralHelper::convertWordPressUri($menuItem);
                $content['fields']['link']['value'] = $uri;
                $content['fields']['navType']['value'] = $navType;
                $content['fields']['navElementId']['value'] = $navElementId;
            }
        }

        if (isset($menuItem->title->rendered)) {
            $content['fields']['title']['value'] = $menuItem->title->rendered;
        }

        $content['fields']['parent']['config']['isAttribute'] = true;
        if (isset($menuItem->parent) && $menuItem->parent) {
            $content['fields']['parent']['value'] = $menuItem->parent;
        } else {
            $content['fields']['parent']['value'] = null;
        }
    }
}
