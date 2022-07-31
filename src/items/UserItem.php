<?php

namespace vnali\migratefromwordpress\items;

use Craft;
use craft\elements\User;
use craft\feedme\Plugin as FeedmePlugin;
use craft\helpers\StringHelper;

use vnali\migratefromwordpress\helpers\Curl;
use vnali\migratefromwordpress\helpers\FieldHelper;
use vnali\migratefromwordpress\helpers\GeneralHelper;

use vnali\migratefromwordpress\MigrateFromWordPress as MigrateFromWordPressPlugin;
use yii\caching\TagDependency;
use yii\web\ServerErrorHttpException;

class UserItem
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
    private $_userItems;


    /**
     * Constructor
     *
     * @param int $page
     * @param int $limit
     * @param string|null $contentLanguage
     */
    public function __construct(int $page, int $limit, string $contentLanguage = null)
    {
        $this->_contentLanguage = $contentLanguage;
        if (!$this->_contentLanguage) {
            $wordpressURL = MigrateFromWordPressPlugin::$plugin->settings->wordpressURL;
        } else {
            $wordpressURL = MigrateFromWordPressPlugin::$plugin->settings->wordpressLanguageSettings[$contentLanguage]['wordpressURL'];
        }
        $wordpressRestApiEndpoint = MigrateFromWordPressPlugin::$plugin->settings->wordpressRestApiEndpoint;
        $address = $wordpressURL . '/' . $wordpressRestApiEndpoint . '/users?per_page=' . $limit . '&page=' . $page;
        $response = Curl::sendToRestAPI($address);
        $response = json_decode($response);
        $this->_userItems = $response;
        // Check if there is next page
        $page = $page + 1;
        $address = $wordpressURL . '/' . $wordpressRestApiEndpoint . '/users?per_page=' . $limit . '&page=' . $page;
        $response = Curl::sendToRestAPI($address);
        $response = json_decode($response);
        if (is_array($response) && isset($response[0]->id)) {
            $this->_hasNext = true;
        }
    }

    /**
     * Get field definition of user items.
     *
     * @return array|null
     */
    public function getFieldDefinitions()
    {
        $content = [];
        $this->_content($this->_userItems[0], $content, 1);
        $this->_fieldDefinitions = FieldHelper::fieldOptions($content, 'user', '');
        Craft::$app->getCache()->set('migrate-from-wordpress-user-fields', json_encode($this->_fieldDefinitions));
        return $this->_fieldDefinitions;
    }

    /**
     * Get values of WordPress user items.
     *
     * @return array
     */
    public function getValues(): array
    {
        $contents = [];
        foreach ($this->_userItems as $userItem) {
            $content = null;
            $this->_content($userItem, $content, 0);
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
     * Get attributes of WordPress user items
     *
     * @param object $userItem
     * @param array|null $content
     * @param int $gettingFields
     */
    private function _content(object $userItem, array &$content = null, int $gettingFields)
    {
        $this->_attributes($userItem, $content, $gettingFields);
        if (!$content) {
            return false;
        }
    }

    /**
     * Get attributes of WordPress user items
     *
     * @param object $userItem
     * @param array|null $content
     * @param int $gettingFields
     */
    private function _attributes(object $userItem, array &$content = null, int $gettingFields)
    {
        $content['fields']['wordpressUUID']['value'] = $userItem->link;
        $content['fields']['wordpressUUID']['config']['type'] = 'text';
        $content['fields']['wordpressUUID']['config']['label'] = 'WordPress UUID';
        $content['fields']['wordpressSiteId']['value'] = MigrateFromWordPressPlugin::$plugin->settings->wordpressURL;
        $content['fields']['wordpressSiteId']['config']['type'] = 'text';
        $content['fields']['wordpressSiteId']['config']['label'] = 'WordPress Site ID';
        $content['fields']['wordpressUserId']['value'] = $userItem->id;
        $content['fields']['wordpressUserId']['config']['type'] = 'text';
        $content['fields']['wordpressUserId']['config']['label'] = 'WordPress User Id';
        $content['fields']['lang']['value'] = 'en';
        $content['fields']['lang']['config']['type'] = 'text';
        $content['fields']['lang']['config']['label'] = 'Lang';

        // We don't use name attribute of rest api because it can be full name.
        // We use slug returned in rest api. Craft doesn't support space in username too
        $username = $userItem->slug;
        $content['fields']['username']['value'] = $username;
        $content['fields']['username']['config']['isAttribute'] = true;

        // Get user name from rest api in case we need it for full name on Craft
        // TODO: also migrate user's name to a text field
        $content['fields']['name']['value'] = $userItem->name;
        $content['fields']['name']['config']['isAttribute'] = true;
        
        $emailAttribute = MigrateFromWordPressPlugin::$plugin->settings->emailAttribute;

        if (isset($userItem->{$emailAttribute})) {
            $email = $userItem->{$emailAttribute};
        } else {
            $email = $username . '@craftcms.test';
        }
        $content['fields']['email']['value'] = $email;
        $content['fields']['email']['config']['isAttribute'] = true;

        $content['fields']['wordpressLink']['value'] = $userItem->link;
        $content['fields']['wordpressLink']['config']['type'] = 'text';
        $content['fields']['wordpressLink']['config']['label'] = 'WordPress link';

        // User's biographical info
        if (isset($userItem->description)) {
            $content['fields']['description']['value'] = $userItem->description;
            if ($gettingFields == 1) {
                $content['fields']['description']['config']['type'] = 'text';
                $content['fields']['description']['config']['label'] = 'Description';
            }
        }

        // User's gravatar
        if (isset($userItem->avatar_urls)) {
            $content['fields']['avatar_url']['value'] = $userItem->avatar_urls->{96};
            if ($gettingFields == 1) {
                $content['fields']['avatar_url']['config']['type'] = 'url';
                $content['fields']['avatar_url']['config']['label'] = 'Avatar URL';
            }
        }

        // User's website
        if (isset($userItem->url)) {
            $content['fields']['website']['value'] = $userItem->url;
            if ($gettingFields == 1) {
                $content['fields']['website']['config']['type'] = 'url';
                $content['fields']['website']['config']['label'] = 'website';
            }
        }

        // Process ACF fields
        if (isset($userItem->acf) && $userItem->acf) {
            $content = GeneralHelper::analyzeACF($userItem, $content);
        }
    }

    /**
     * Create feed to migrate WordPress users to Craft users
     *
     * @param array $fieldMappings
     * @param string $wordpressLanguage
     * @return void
     */
    public static function createFeed(array $fieldMappings, string $wordpressLanguage)
    {
        $secret = Craft::$app->getCache()->get('migrate-from-wordpress-protect-feed-values');
        if (!$secret) {
            $secret = Craft::$app->getSecurity()->generateRandomString();
            Craft::$app->getCache()->set('migrate-from-wordpress-protect-feed-values', $secret, 0, new TagDependency(['tags' => 'migrate-from-wordpress']));
        }

        $uniqueFields = [];
        $uniqueFields['username'] = 1;

        $duplicateHandle = ['add', 'update'];

        $limit = MigrateFromWordPressPlugin::$plugin->settings->restItemLimit;
        $feedUrl = Craft::getAlias('@web') . "/migrate-from-wordpress/users/values?token=$secret&contentLanguage=" . $wordpressLanguage . "&limit=" . $limit;
        $wordpressURL = MigrateFromWordPressPlugin::$plugin->settings->wordpressURL;

        $model = new \craft\feedme\models\FeedModel();
        $feedName = self::MIGRATE_FROM_WORDPRESS . "migrate users - " . $wordpressURL;
        $model->name = $feedName;
        $model->feedUrl = $feedUrl;
        $model->feedType = 'json';
        $model->elementType = User::class;
        $model->primaryElement = 'data';
        $model->backup = true;
        $model->duplicateHandle = $duplicateHandle;
        $model->passkey = StringHelper::randomString(10);
        $model->fieldMapping = $fieldMappings;
        $model->fieldUnique = $uniqueFields;
        $model->paginationNode = 'next';
        if (!FeedmePlugin::$plugin->feeds->savefeed($model)) {
            throw new ServerErrorHttpException('user feed cannot be created');
        }
    }
}
