<?php

namespace vnali\migratefromwordpress\items;

use Craft;
use Symfony\Component\DomCrawler\Crawler;
use vnali\migratefromwordpress\helpers\Curl;
use vnali\migratefromwordpress\helpers\FieldHelper;
use vnali\migratefromwordpress\helpers\GeneralHelper;

use vnali\migratefromwordpress\MigrateFromWordPress as MigrateFromWordPressPlugin;
use yii\caching\TagDependency;

class PostItem
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
    private $_postType;

    /**
     * @var array
     */
    private $_postItems;

    /**
     * @var string
     */
    private $_restApiAddress;

    /**
     * Constructor.
     *
     * @param string $postType
     * @param int $page
     * @param int $limit
     * @param string $contentLanguage
     */
    public function __construct($postType, int $page, int $limit, string $contentLanguage)
    {
        $this->_postType = $postType;
        $this->_contentLanguage = $contentLanguage;
        if (
            !$this->_contentLanguage ||
            !isset(MigrateFromWordPressPlugin::$plugin->settings->wordpressLanguageSettings[$contentLanguage]['wordpressURL'])
        ) {
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
        $address = $this->_restApiAddress . '/posts' . $separator . 'per_page=' . $limit . '&page=' . $page . $status . $protectedItemsPasswords;
        $response = Curl::sendToRestAPI($address);
        $response = json_decode($response);
        $this->_postItems = $response;

        // Check if there is next item
        $page = $page + 1;
        $address = $this->_restApiAddress . '/posts' . $separator . 'per_page=' . $limit . '&page=' . $page . $status . $protectedItemsPasswords;
        $response = Curl::sendToRestAPI($address);
        $response = json_decode($response);
        if (is_array($response) && isset($response[0]->id)) {
            $this->_hasNext = true;
        }
    }

    /**
     * Get field definition of post items.
     *
     * @return array|null
     */
    public function getFieldDefinitions()
    {
        if (isset($this->_postItems[0])) {
            $content = [];
            $this->_content($this->_postItems[0], $content, 1);
            $this->_fieldDefinitions = FieldHelper::fieldOptions($content, 'post-' . $this->_postType, '');
        } else {
            $this->_fieldDefinitions = null;
        }
        Craft::$app->cache->set('migrate-from-wordpress-post-' . $this->_postType . '-fields', json_encode($this->_fieldDefinitions), 0, new TagDependency(['tags' => 'migrate-from-wordpress']));
        return $this->_fieldDefinitions;
    }

    /**
     * Get values of WordPress post items
     *
     * @return array
     */
    public function getValues(): array
    {
        $this->_fieldDefinitions = json_decode(Craft::$app->cache->get('migrate-from-wordpress-post-' . $this->_postType . '-fields'), true);
        $contents = [];
        foreach ($this->_postItems as $postItem) {
            $content = null;
            $this->_content($postItem, $content, 0);
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
     * Get attributes and relationships of WordPress post items
     *
     * @param object $postItem
     * @param array|null $content
     * @param int $gettingFields
     */
    private function _content(object $postItem, array &$content = null, int $gettingFields)
    {
        $this->_attributes($postItem, $content, $gettingFields);

        if (!$content) {
            return false;
        }
    }

    /**
     * Get attributes of WordPress post items
     *
     * @param object $postItem
     * @param array|null $content
     * @param int $gettingFields
     */
    private function _attributes(object $postItem, array &$content = null, int $gettingFields)
    {
        if (isset($postItem->id)) {
            $content['fields']['wordpressSiteId']['value'] = MigrateFromWordPressPlugin::$plugin->settings->wordpressURL;
            $content['fields']['wordpressSiteId']['config']['type'] = 'text';
            $content['fields']['wordpressSiteId']['config']['label'] = 'WordPress Site ID';
            $content['fields']['wordpressPostId']['value'] = $postItem->id;
            $content['fields']['wordpressPostId']['config']['type'] = 'text';
            $content['fields']['wordpressPostId']['config']['label'] = 'WordPress post Id';
            if (isset($postItem->link)) {
                $content['fields']['wordpressLink']['value'] = $postItem->link;
                $content['fields']['wordpressLink']['config']['type'] = 'text';
                $content['fields']['wordpressLink']['config']['label'] = 'WordPress link';
                $content['fields']['wordpressUUID']['value'] = $postItem->link;
                $content['fields']['wordpressUUID']['config']['type'] = 'text';
                $content['fields']['wordpressUUID']['config']['label'] = 'WordPress UUID';
            }
            // Sticky
            if (isset($postItem->sticky)) {
                $content['fields']['sticky']['value'] = $postItem->sticky;
                $content['fields']['sticky']['config']['type'] = 'boolean';
                $content['fields']['sticky']['config']['label'] = 'Sticky';
            }
            // Comment status
            if (isset($postItem->comment_status)) {
                $content['fields']['comment-status']['value'] = $postItem->comment_status;
                $content['fields']['comment-status']['config']['type'] = 'text';
                $content['fields']['comment-status']['config']['label'] = 'Comment status';
                $content['fields']['comment-status']['config']['translatable'] = 'yes';
            }
            // Ping status
            if (isset($postItem->ping_status)) {
                $content['fields']['ping-status']['value'] = $postItem->ping_status;
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

            if (isset($postItem->date_gmt)) {
                // We currently use date_gmt attribute and set timezone to GMT and feed-me import it with the correct timezone specified in the general setting
                // But it seems date_gmt doesn't change if WordPress time zone setting change
                // TODO: Maybe a better solution is to use the date attribute instead of date_gmt and ask the user what the WordPress time zone is.?
                $content['fields']['created']['value'] = strtotime($postItem->date_gmt . ' GMT');
                $content['fields']['created']['config']['isAttribute'] = true;
            }

            // Craft status
            $status = 0;
            // Draft or need review future posts has no future status, so we can set the status of future posts to enable
            if (isset($postItem->status) && ($postItem->status == 'publish' || $postItem->status == 'future')) {
                $status = 1;
            }
            $content['fields']['status']['value'] = $status;
            $content['fields']['status']['config']['isAttribute'] = true;

            // Password protect
            if (isset($postItem->content->protected)) {
                $content['fields']['password-protected']['value'] = $postItem->content->protected;
                $content['fields']['password-protected']['config']['type'] = 'boolean';
                $content['fields']['password-protected']['config']['label'] = 'Password Protected';
                $content['fields']['password-protected']['config']['translatable'] = 'yes';
            }
            // WordPress status
            if (isset($postItem->status)) {
                $content['fields']['wordpress-status']['value'] = $postItem->status;
                $content['fields']['wordpress-status']['config']['type'] = 'text';
                $content['fields']['wordpress-status']['config']['label'] = 'status';
                $content['fields']['wordpress-status']['config']['translatable'] = 'yes';
            }
        }

        $content['fields']['title']['value'] = $postItem->title->rendered;
        $content['fields']['title']['config']['isAttribute'] = true;

        // Author
        if (isset($postItem->author)) {
            $authorId = $postItem->author;
            $address = $this->_restApiAddress . '/users/' . $authorId;
            $response = Curl::sendToRestAPI($address);
            $response = json_decode($response);
            if (isset($response->link)) {
                $content['fields']['authorId']['value'] = $response->link;
            }
        }
        $content['fields']['authorId']['config']['isAttribute'] = true;

        $content['fields']['uuid']['value'] = $postItem->id;
        $content['fields']['uuid']['config']['isAttribute'] = true;

        if (isset($postItem->excerpt->rendered)) {
            $output = $postItem->excerpt->rendered;
            $content['fields']['excerpt']['value'] = $output;
            if ($gettingFields == 1) {
                $content['fields']['excerpt']['config']['type'] = 'text';
                $content['fields']['excerpt']['config']['label'] = 'excerpt';
            }
        }

        $gutenbergSettings = Craft::$app->getCache()->get('migrate-from-wordpress-post-gutenberg-' . $this->_postType);
        if (!$gutenbergSettings) {
            // Currently it happens when use troubleshoot utility before migrate
            $gutenbergSettings['migrate'] = true;
            //throw new ServerErrorHttpException('You should migrate post items once');
        }
        $migrateGutenbergBlocks = $gutenbergSettings['migrate'];
        if (isset($postItem->content->rendered)) {
            if ($migrateGutenbergBlocks == 'true') {
                $output = $postItem->content->rendered;
                if (MigrateFromWordPressPlugin::$plugin->settings->addExcerptToBody && isset($postItem->excerpt->rendered)) {
                    $output = $postItem->excerpt->rendered . $output;
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
                $output = $postItem->content->rendered;
                if (MigrateFromWordPressPlugin::$plugin->settings->addExcerptToBody && isset($postItem->excerpt->rendered)) {
                    $output = $postItem->excerpt->rendered . '<br>' . $output;
                }
                $content['fields']['body']['value'] = $output;
                $content['fields']['body']['config']['type'] = 'text';
                $content['fields']['body']['config']['label'] = 'body';
            }
        }

        if (isset($postItem->tags)) {
            $content['fields']['tags']['value'] = $postItem->tags;
            $content['fields']['tags']['config']['type'] = 'tag';
            $content['fields']['tags']['config']['label'] = 'tags';
        }

        if (isset($postItem->categories)) {
            $content['fields']['categories']['value'] = $postItem->categories;
            $content['fields']['categories']['config']['type'] = 'category';
            $content['fields']['categories']['config']['label'] = 'categories';
        }

        if (isset($postItem->featured_media)) {
            $content['fields']['featuredMedia']['config']['type'] = 'file';
            $content['fields']['featuredMedia']['config']['label'] = 'Featured Media';
        }

        if (isset($postItem->featured_media) && $postItem->featured_media) {
            $content['fields']['featuredMedia']['value'] = $postItem->featured_media;
        } else {
            $content['fields']['featuredMedia']['value'] = null;
        }

        if (isset($postItem->acf) && $postItem->acf) {
            $content = GeneralHelper::analyzeACF($postItem, $content);
        }

        // Yoast SEO Data
        if (MigrateFromWordPressPlugin::$plugin->settings->yoastSEO && isset($postItem->yoast_head_json)) {
            $content['fields']['yoastSEO']['value'] = $postItem->yoast_head_json;
        }

        // Save uri in cache for later
        $contentIds = json_decode(Craft::$app->cache->get('migrate-from-wordpress-pages-posts-id-and-url'), true);
        $contentIds[$postItem->link] = $postItem->id;
        Craft::$app->cache->set('migrate-from-wordpress-pages-posts-id-and-url', json_encode($contentIds), 0, new TagDependency(['tags' => ['migrate-from-wordpress']]));
    }

    /**
     * Return post items
     *
     * @return array
     */
    public function getPostItems(): array
    {
        return $this->_postItems;
    }
}
