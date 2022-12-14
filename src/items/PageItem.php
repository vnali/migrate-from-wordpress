<?php

namespace vnali\migratefromwordpress\items;

use Craft;
use Symfony\Component\DomCrawler\Crawler;
use vnali\migratefromwordpress\helpers\Curl;
use vnali\migratefromwordpress\helpers\FieldHelper;
use vnali\migratefromwordpress\helpers\GeneralHelper;
use vnali\migratefromwordpress\MigrateFromWordPress as MigrateFromWordPressPlugin;

use yii\caching\TagDependency;

class PageItem
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
     * @var string
     */
    private $_pageType;

    /**
     * @var array
     */
    private $_pageItems;

    /**
     * @var string
     */
    private $_restApiAddress;

    /**
     * Constructor.
     *
     * @param string $pageType
     * @param int $page
     * @param int $limit
     * @param string $contentLanguage
     */
    public function __construct($pageType, int $page, int $limit, string $contentLanguage)
    {
        $this->_pageType = $pageType;
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
        // Create status query string
        $status = '';
        $migrateNotPublicStatus = MigrateFromWordPressPlugin::$plugin->settings->migrateNotPublicStatus;
        if ($migrateNotPublicStatus) {
            $status = 'status[]=any';
        }
        $migrateTrashStatus = MigrateFromWordPressPlugin::$plugin->settings->migrateTrashStatus;
        if ($migrateTrashStatus) {
            if ($status) {
                $status = $status . '&status[]=trash';
            } else {
                $status = 'status[]=trash';
            }
        }
        if ($status) {
            $status = '&' . $status;
        }
        $protectedItemsPasswords = MigrateFromWordPressPlugin::$plugin->settings->protectedItemsPasswords;
        if ($protectedItemsPasswords) {
            $protectedItemsPasswords = '&password=' . $protectedItemsPasswords;
        }
        //
        $address = $this->_restApiAddress . '/pages' . $separator . 'per_page=' . $limit . '&page=' . $page . $status . $protectedItemsPasswords;
        $response = Curl::sendToRestAPI($address);
        $response = json_decode($response);
        $this->_pageItems = $response;

        // Check if there is next item
        $page = $page + 1;
        $address = $this->_restApiAddress . '/pages' . $separator . 'per_page=' . $limit . '&page=' . $page . $status . $protectedItemsPasswords;
        $response = Curl::sendToRestAPI($address);
        $response = json_decode($response);
        if (is_array($response) && isset($response[0]->id)) {
            $this->_hasNext = true;
        }
    }

    /**
     * Get field definition of page items.
     *
     * @return array|null
     */
    public function getFieldDefinitions()
    {
        if (isset($this->_pageItems[0])) {
            $content = [];
            $this->_content($this->_pageItems[0], $content, 1);
            $this->_fieldDefinitions = FieldHelper::fieldOptions($content, 'page-' . $this->_pageType, '');
        } else {
            $this->_fieldDefinitions = null;
        }
        Craft::$app->cache->set('migrate-from-wordpress-page-' . $this->_pageType . '-fields', json_encode($this->_fieldDefinitions), 0, new TagDependency(['tags' => 'migrate-from-wordpress']));
        return $this->_fieldDefinitions;
    }

    /**
     * Get values of WordPress page items
     *
     * @return array
     */
    public function getValues(): array
    {
        $this->_fieldDefinitions = json_decode(Craft::$app->cache->get('migrate-from-wordpress-page-' . $this->_pageType . '-fields'), true);
        $contents = [];
        foreach ($this->_pageItems as $pageItem) {
            $content = null;
            $this->_content($pageItem, $content, 0);
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
     * Get attributes and relationships of WordPress page items
     *
     * @param object $pageItem
     * @param array|null $content
     * @param int $gettingFields
     */
    private function _content(object $pageItem, array &$content = null, int $gettingFields)
    {
        $this->_attributes($pageItem, $content, $gettingFields);

        if (!$content) {
            return false;
        }
    }

    /**
     * Get attributes of WordPress page items
     *
     * @param object $pageItem
     * @param array|null $content
     * @param int $gettingFields
     */
    private function _attributes(object $pageItem, array &$content = null, int $gettingFields)
    {
        if (isset($pageItem->id)) {
            $content['fields']['wordpressSiteId']['value'] = MigrateFromWordPressPlugin::$plugin->settings->wordpressURL;
            $content['fields']['wordpressSiteId']['config']['type'] = 'text';
            $content['fields']['wordpressSiteId']['config']['label'] = 'WordPress Site ID';
            $content['fields']['wordpressPostId']['value'] = $pageItem->id;
            $content['fields']['wordpressPostId']['config']['type'] = 'text';
            $content['fields']['wordpressPostId']['config']['label'] = 'WordPress post Id';
            if (isset($pageItem->link)) {
                $content['fields']['wordpressLink']['value'] = $pageItem->link;
                $content['fields']['wordpressLink']['config']['type'] = 'text';
                $content['fields']['wordpressLink']['config']['label'] = 'WordPress link';
                $content['fields']['wordpressUUID']['value'] = $pageItem->link;
                $content['fields']['wordpressUUID']['config']['type'] = 'text';
                $content['fields']['wordpressUUID']['config']['label'] = 'WordPress UUID';
            }
            // Comment status
            if (isset($pageItem->comment_status)) {
                $content['fields']['comment-status']['value'] = $pageItem->comment_status;
                $content['fields']['comment-status']['config']['type'] = 'text';
                $content['fields']['comment-status']['config']['label'] = 'Comment status';
                $content['fields']['comment-status']['config']['translatable'] = 'yes';
            }
            // Ping status
            if (isset($pageItem->ping_status)) {
                $content['fields']['ping-status']['value'] = $pageItem->ping_status;
                $content['fields']['ping-status']['config']['type'] = 'text';
                $content['fields']['ping-status']['config']['label'] = 'Ping status';
                $content['fields']['ping-status']['config']['translatable'] = 'yes';
            }

            $content['fields']['lang']['value'] = 'en';

            if ($gettingFields == 0) {
                $itemLanguage = $content['fields']['lang']['value'];
                if ($itemLanguage != $this->_contentLanguage) {
                    $content = null;
                    return false;
                }
            }

            /*
            $content['fields']['wordpressSourceLanguage']['value'] = 'en';
            $content['fields']['wordpressSourceLanguage']['config']['type'] = 'text';
            $content['fields']['wordpressSourceLanguage']['config']['label'] = 'wordpressSourceLanguage';
            $content['fields']['wordpressSourceLanguage']['config']['translatable'] = 'yes';
            */

            $content['fields']['lang']['config']['type'] = 'text';
            $content['fields']['lang']['config']['label'] = 'Lang';
            if (isset($pageItem->date_gmt)) {
                $content['fields']['created']['value'] = strtotime($pageItem->date_gmt . ' GMT');
                $content['fields']['created']['config']['isAttribute'] = true;
            }

            // Craft status
            $status = 0;
            if (isset($pageItem->status) && ($pageItem->status == 'publish' || $pageItem->status == 'future')) {
                $status = 1;
            }
            $content['fields']['status']['value'] = $status;
            $content['fields']['status']['config']['isAttribute'] = true;

            // Password protect
            if (isset($pageItem->content->protected)) {
                $content['fields']['password-protected']['value'] = $pageItem->content->protected;
                $content['fields']['password-protected']['config']['type'] = 'boolean';
                $content['fields']['password-protected']['config']['label'] = 'Password Protected';
                $content['fields']['password-protected']['config']['translatable'] = 'yes';
            }
            // Save wordpress status as text
            if (isset($pageItem->status)) {
                $content['fields']['wordpress-status']['value'] = $pageItem->status;
                $content['fields']['wordpress-status']['config']['type'] = 'text';
                $content['fields']['wordpress-status']['config']['label'] = 'wordpress status';
                $content['fields']['wordpress-status']['config']['translatable'] = 'yes';
            }
        }

        $content['fields']['title']['value'] = $pageItem->title->rendered;
        $content['fields']['title']['config']['isAttribute'] = true;

        // Author
        if (isset($pageItem->author)) {
            $authorId = $pageItem->author;
            $address = $this->_restApiAddress . '/users/' . $authorId;
            $response = Curl::sendToRestAPI($address);
            $response = json_decode($response);
            if (isset($response->link)) {
                $content['fields']['authorId']['value'] = $response->link;
            }
        }
        $content['fields']['authorId']['config']['isAttribute'] = true;

        $content['fields']['uuid']['value'] = $pageItem->id;
        $content['fields']['uuid']['config']['isAttribute'] = true;


        if (isset($pageItem->excerpt->rendered)) {
            $output = $pageItem->excerpt->rendered;
            $content['fields']['excerpt']['value'] = $output;
            if ($gettingFields == 1) {
                $content['fields']['excerpt']['config']['type'] = 'text';
                $content['fields']['excerpt']['config']['label'] = 'excerpt';
            }
        }

        $gutenbergSettings = Craft::$app->getCache()->get('migrate-from-wordpress-page-gutenberg-' . $this->_pageType);
        if (!$gutenbergSettings) {
            // Currently it happens when use troubleshoot utility before migrate
            $gutenbergSettings['migrate'] = true;
            //throw new ServerErrorHttpException('you should migrate page items once');
        }
        $migrateGutenbergBlocks = $gutenbergSettings['migrate'];

        if (isset($pageItem->content->rendered)) {
            if ($migrateGutenbergBlocks == 'true') {
                $output = $pageItem->content->rendered;
                if (MigrateFromWordPressPlugin::$plugin->settings->addExcerptToBody && isset($pageItem->excerpt->rendered)) {
                    $output = $pageItem->excerpt->rendered . $output;
                }
                $crawler = new Crawler($output);
                $c = $crawler->filter('html body div section');
                $node = $c->getNode(0);
                if (!$node) {
                    $output = GeneralHelper::analyzeGutenberg($output, false);
                } else {
                    $output = GeneralHelper::analyzeElementor($output, false);
                }
                $content['fields']['body'] = $output;
                $content['fields']['body']['config']['type'] = 'gutenberg/elementor';
                $content['fields']['body']['config']['label'] = 'body';
            } else {
                $output = $pageItem->content->rendered;
                $content['fields']['body']['value'] = $output;
                $content['fields']['body']['config']['type'] = 'text';
                $content['fields']['body']['config']['label'] = 'body';
            }
        }

        if (isset($pageItem->tags)) {
            $content['fields']['tags']['value'] = $pageItem->tags;
            $content['fields']['tags']['config']['type'] = 'tag';
            $content['fields']['tags']['config']['label'] = 'tags';
        }

        if (isset($pageItem->categories)) {
            $content['fields']['categories']['value'] = $pageItem->categories;
            $content['fields']['categories']['config']['type'] = 'category';
            $content['fields']['categories']['config']['label'] = 'categories';
        }

        if (isset($pageItem->featured_media)) {
            $content['fields']['featuredMedia']['config']['type'] = 'file';
            $content['fields']['featuredMedia']['config']['label'] = 'Featured Media';
        }

        if (isset($pageItem->featured_media) && $pageItem->featured_media) {
            $content['fields']['featuredMedia']['value'] = $pageItem->featured_media;
        } else {
            $content['fields']['featuredMedia']['value'] = null;
        }

        if (isset($pageItem->acf) && $pageItem->acf) {
            $content = GeneralHelper::analyzeACF($pageItem, $content);
        }

        // Yoast SEO Data
        if (MigrateFromWordPressPlugin::$plugin->settings->yoastSEO && isset($pageItem->yoast_head_json)) {
            $content['fields']['yoastSEO']['value'] = $pageItem->yoast_head_json;
        }

        // Save uri in cache for later
        $contentIds = json_decode(Craft::$app->cache->get('migrate-from-wordpress-pages-posts-id-and-url'), true);
        $contentIds[$pageItem->link] = $pageItem->id;
        Craft::$app->cache->set('migrate-from-wordpress-pages-posts-id-and-url', json_encode($contentIds), 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));
    }

    /**
     * Return page items
     *
     * @return array
     */
    public function getPageItems(): array
    {
        return $this->_pageItems;
    }
}
