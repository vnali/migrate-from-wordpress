<?php

namespace vnali\migratefromwordpress\models;

use Craft;
use craft\base\Model;

use vnali\migratefromwordpress\helpers\Curl;
use vnali\migratefromwordpress\MigrateFromWordPress as MigrateFromWordPressPlugin;

class Settings extends Model
{
    /**
     * @var bool
     */
    public $addExcerptToBody = false;

    /**
     * @var bool
     */
    public $allowHttpWordPressSite = false;

    /**
     * @var bool
     */
    public $allowReusingToken = false;

    /**
     * @var int
     */
    public $cacheFeedValuesSeconds = 0;

    /**
     * @var string
     */
    public $categoryBase = 'category';

    /**
     * @var bool
     */
    public $clearAllCache = false;

    /**
     * @var bool
     */
    public $clearFeedmeLogs = false;

    /**
     * @var bool
     */
    public $clearFeedsCreatedByPlugin = false;

    /**
     * @var bool
     */
    public $deleteAllCategories;

    /**
     * @var bool
     */
    public $deleteAllFields;

    /**
     * @var bool
     */
    public $deleteAllGlobals;

    /**
     * @var bool
     */
    public $deleteAllSections;

    /**
     * @var bool
     */
    public $deleteAllTags;

    /**
     * @var bool
     */
    public $deleteAllVolumes;

    /**
     * @var string
     */
    public $emailAttribute = 'email';

    /**
     * @var bool
     */
    public $fetchFilesByAssetIndex = true;

    /**
     * @var bool
     */
    public $ignoreWordPressUploadPath = true;

    /**
     * @var string
     */
    public $oldWordPressURL;

    /**
     * @var bool
     */
    public $migrateUserChanged = true;

    /**
     * @var bool
     */
    public $migrateUserCreated = true;

    /**
     * @var bool
     */
    public $migrateNotPublicStatus = true;

    /**
     * @var string
     */
    public $protectedItemsPasswords = '';

    /**
     * @var bool
     */
    public $migrateTrashStatus = false;

    /**
     * @var int
     */
    public $restItemLimit = 10;

    /**
     * @var int
     */
    public $step = 1;

    /**
     * @var string
     */
    public $tagBase = 'tag';

    /**
     * @var string
     */
    public $wordpressPassword;

    /**
     * @var string
     */
    public $wordpressAccountUsername;

    /**
     * @var array|string
     */
    public $wordpressLanguageSettings;

    /**
     * @var string
     */
    public $wordpressRestApiEndpoint = 'wp-json/wp/v2';

    /**
     * @var string
     */
    public $wordpressSystemPath;

    /**
     * @var string
     */
    public $wordpressUploadPath = '/wp-content/uploads/';

    /**
     * @var string
     */
    public $wordpressURL;

    /**
     * @var bool
     */
    public $yoastSEO = false;

    public function rules(): array
    {
        return [
            [['addExcerptToBody', 'ignoreWordPressUploadPath', 'migrateNotPublicStatus', 'migrateTrashStatus', 'yoastSEO'], 'in', 'range' => ['0', '1']],
            [['categoryBase', 'protectedItemsPasswords', 'tagBase', 'wordpressUploadPath'], 'string', 'max' => 255],
            [['restItemLimit'], 'integer', 'min' => 1, 'max' => 50],
            [['wordpressURL'], function($attribute, $params, $validator) {
                if ((!Craft::$app->plugins->isPluginInstalled('feed-me') || !Craft::$app->plugins->isPluginEnabled('feed-me'))) {
                    $this->addError($attribute, 'Make sure the Feedme plugin is installed and enabled.');
                } elseif (parse_url($this->wordpressURL, PHP_URL_SCHEME) == 'http' && !MigrateFromWordPressPlugin::$plugin->getSettings()->allowHttpWordPressSite) {
                    $this->addError($attribute, 'WordPress URL can not be HTTP. We pass the admin\'s password to the WordPress site. If your WordPress site is on local, override allowHttpWordPressSite to true.');
                } else {
                    $handle = curl_init($this->wordpressURL);
                    curl_setopt($handle,  CURLOPT_RETURNTRANSFER, true);
                    $response = curl_exec($handle);
                    $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
                    if (!$httpCode || $httpCode != 200) {
                        $this->addError($attribute, 'URL is not accessible');
                    }
                    curl_close($handle);
                }
            }, 'skipOnEmpty' => false],
            [['wordpressRestApiEndpoint'], function($attribute, $params, $validator) {
                // It seems on some environment we get errors when checking rest api is called without username and password
                // Check for rest api endpoint only if WordPress url is specified
                if ($this->wordpressURL) {
                    $handle = curl_init($this->wordpressURL . '/' . $this->wordpressRestApiEndpoint);
                    curl_setopt($handle, CURLOPT_FRESH_CONNECT, true);
                    curl_setopt($handle,  CURLOPT_RETURNTRANSFER, true);
                    $response = curl_exec($handle);
                    $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
                    $response = json_decode($response);
                    // Check only if URL is founded
                    if (!$httpCode || $httpCode == 404) {
                        $this->addError($attribute, 'REST API URL is not accessible.');
                    }
                    curl_close($handle);
                }
            }, 'skipOnEmpty' => false],
            [['wordpressPassword'], function($attribute, $params, $validator) {
                $address = $this->wordpressURL . '/' . $this->wordpressRestApiEndpoint . '/settings';
                $response = Curl::sendToRestAPI($address);
                $response = json_decode($response);
                if (isset($response->code) && $response->code == 'rest_no_route') {
                    $this->addError('wordpressRestApiEndpoint', 'No route was found matching the URL and request method');
                } elseif (isset($response->code) && $response->code == 'rest_forbidden') {
                    $this->addError($attribute, 'rest request is forbidden. make sure basic auth is enabled');
                } elseif (isset($response->error)) {
                    // Error for basic auth
                    $this->addError($attribute, $response->error_description ?? $response->error);
                } elseif (isset($response->data->status) && $response->data->status == 401) {
                    // Error for application password
                    $this->addError($attribute, $response->message ?? '401');
                } elseif (!isset($response->title)) {
                    $this->addError($attribute, 'Data from specified Rest API is not valid');
                }
            }, 'skipOnEmpty' => false],
            [['wordpressLanguageSettings'], function($attribute, $params, $validator) {
                $wordpressLanguageSettings = $this->wordpressLanguageSettings;
                if (is_array($wordpressLanguageSettings)) {
                    $enableForMigration = false;
                    foreach ($wordpressLanguageSettings as $key => $wordpressLanguageSetting) {
                        if ($wordpressLanguageSetting['enableForMigration']) {
                            $enableForMigration = true;
                            break;
                        }
                    }
                    if (!$enableForMigration) {
                        $this->addError($attribute, 'There should be at least one language.');
                    }
                }
                if (!empty($wordpressLanguageSettings)) {
                    foreach ($wordpressLanguageSettings as $key => $wordpressLanguageSetting) {
                        $wordpressLanguageURL = $wordpressLanguageSetting['wordpressURL'];
                        if ($wordpressLanguageSetting['enableForMigration'] && !in_array($wordpressLanguageSetting['enableForMigration'], ['0', '1'])) {
                            $this->addError($attribute, $wordpressLanguageSetting['enableForMigration'] . ' is not valid');
                            $this->wordpressLanguageSettings[$key]['error']['enableForMigration'] = 1;
                        }
                        if ($wordpressLanguageSetting['enableForMigration'] && filter_var($wordpressLanguageURL, FILTER_VALIDATE_URL) === false) {
                            $this->addError($attribute, $wordpressLanguageURL . ' is not valid URL');
                            $this->wordpressLanguageSettings[$key]['error']['wordpressURL'] = 1;
                            continue;
                        }
                        if ($wordpressLanguageSetting['enableForMigration']) {
                            if (parse_url($wordpressLanguageURL, PHP_URL_SCHEME) == 'http' && !MigrateFromWordPressPlugin::$plugin->getSettings()->allowHttpWordPressSite) {
                                $this->addError($attribute, 'WordPress URL can not be HTTP. We pass the admin\'s password to the WordPress site. If your WordPress site is on local, override allowHttpWordPressSite to true.');
                            }
                            $user = $this->wordpressAccountUsername;
                            $password = $this->wordpressPassword;
                            $address = $wordpressLanguageURL . '/' . $this->wordpressRestApiEndpoint . '/settings';
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_POST, 0);
                            curl_setopt($ch, CURLOPT_URL, $address);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $password);
                            curl_setopt($ch, CURLINFO_HEADER_OUT, true);
                            $response = trim(curl_exec($ch));
                            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            $response = json_decode($response);
                            if (!$httpCode || $httpCode != 200) {
                                $this->addError($attribute, $wordpressLanguageSetting['wordpressURL'] . ' REST API is not accessible');
                                $this->wordpressLanguageSettings[$key]['error']['wordpressURL'] = 1;
                            } elseif (isset($response->code) && $response->code == 'rest_forbidden') {
                                $this->addError($attribute, 'rest request is forbidden. make sure basic auth is enabled');
                            } elseif (isset($response->error)) {
                                $this->addError($attribute, $response->error_description ?? $response->error);
                            } elseif (isset($response->data->status) && $response->data->status == 401) {
                                $this->addError($attribute, $response->message ?? '401');
                            }
                            curl_close($ch);
                        }
                    }
                }
            }, 'skipOnEmpty' => false],
        ];
    }
}
