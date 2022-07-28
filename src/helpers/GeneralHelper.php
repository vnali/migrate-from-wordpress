<?php

namespace vnali\migratefromwordpress\helpers;

use Craft;

use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\feedme\records\FeedRecord;
use craft\web\View;

use Symfony\Component\DomCrawler\Crawler;

use verbb\supertable\SuperTable;

use vnali\migratefromwordpress\MigrateFromWordPress as MigrateFromWordPressPlugin;

use yii\caching\TagDependency;
use yii\web\ServerErrorHttpException;

class GeneralHelper
{
    /**
     * Process WordPress URI
     *
     * @param string $uri
     * @return array
     */
    public static function analyzeWordPressUri(string $uri): array
    {
        $folder = dirname($uri);
        $filename = basename($uri);
        if (!MigrateFromWordPressPlugin::$plugin->settings->ignoreWordPressUploadPath) {
            $folder = MigrateFromWordPressPlugin::$plugin->settings->wordpressUploadPath . $folder;
            $folder = ltrim($folder, '/');
            $folder = rtrim($folder, '/');
        }
        return array($folder, $filename);
    }

    /**
     * Get Tables, Matrix and super tables.
     *
     * @param string $containerTypes
     * @param string $limitFieldsToLayout
     * @param string $item
     * @param int $entryTypeId
     * @param int $onlyContainer
     * @return array
     */

    public static function containers(string $containerTypes = 'all', string $limitFieldsToLayout = 'false', string $item = null, int $entryTypeId = null, int $onlyContainer = 1): array
    {
        $containers = [];
        if ($limitFieldsToLayout == 'true') {
            switch ($item) {
                case 'page':
                case 'post':
                    if ($entryTypeId) {
                        $entryType = Craft::$app->sections->getEntryTypeById($entryTypeId);
                        $fieldLayout = $entryType->getFieldLayout();
                        $fields = $fieldLayout->getCustomFields();
                    } else {
                        $fields = Craft::$app->fields->getAllFields();
                    }
                    break;
                case 'user':
                    $user = User::find()->one();
                    $fieldLayout = $user->getFieldLayout();
                    $fields = $fieldLayout->getCustomFields();
                    break;
                default:
                    throw new ServerErrorHttpException('not supported');
            }
        } else {
            $fields = Craft::$app->fields->getAllFields();
        }
        foreach ($fields as $field) {
            if (($containerTypes == 'all' || $containerTypes == 'craft\fields\Matrix') && get_class($field) == 'craft\fields\Matrix') {
                if ($onlyContainer) {
                    $containers[] = ['value' => $field->handle, 'label' => $field->name];
                } else {
                    $types = GeneralHelper::getContainerInside($field);
                    foreach ($types as $type) {
                        $containers[] = $type;
                    }
                }
            }

            // TODO: add super table support
            /* @phpstan-ignore-next-line */
            /*
            if (($containerTypes == 'all' || $containerTypes == 'verbb\\supertable\\fields\\SuperTableField') && get_class($field) == 'verbb\\supertable\\fields\\SuperTableField') {
                if ($onlyContainer) {
                    $containers[] = ['value' => $field->handle, 'label' => $field->name];
                } else {
                    $types = GeneralHelper::getContainerInside($field);
                    foreach ($types as $type) {
                        $containers[] = $type;
                    }
                }
            }
            */

            if (($containerTypes == 'all' || $containerTypes == 'craft\fields\Table') && get_class($field) == 'craft\fields\Table') {
                if ($onlyContainer) {
                    $containers[] = ['value' => $field->handle, 'label' => $field->name];
                } else {
                    $containers[] = ['value' => $field->handle . '-Table|' . $field->handle, 'label' => $field->name . ' (T)'];
                }
            }
        }
        return $containers;
    }

    /**
     * Get Matrix inside, blocks and tables.
     *
     * @param FieldInterface $field
     * @return array
     */
    public static function getContainerInside(FieldInterface $field): array
    {
        $containers = [];
        if (get_class($field) == 'craft\fields\Matrix') {
            $blockTypes = Craft::$app->matrix->getBlockTypesByFieldId($field->id);
            foreach ($blockTypes as $key => $blockType) {
                $containers[] = ['value' => $field->handle . '-Matrix|' . $blockType->handle . '-BlockType', 'label' => $field->name . '(M) | ' . $blockType->name . '(BT)'];
                $blockTypeFields = $blockType->getCustomFields();
                foreach ($blockTypeFields as $blockTypeField) {
                    if (get_class($blockTypeField) == 'craft\fields\Table') {
                        $containers[] = ['value' => $field->handle . '-Matrix|' . $blockType->handle . '-BlockType|' . $blockTypeField->handle . '-Table', 'label' => $field->name . '(M) | ' . $blockType->name . '(BT) | ' . $blockTypeField->name . ' (T)'];
                    }
                }
            }
        }
        /*
        elseif (get_class($field) == 'verbb\supertable\fields\SuperTableField') {
            $blockTypes = SuperTable::$plugin->service->getBlockTypesByFieldId($field->id);
            foreach ($blockTypes as $key => $blockType) {
                $blockTypeFields = $blockType->getCustomFields();
                $containers[] = ['value' => $field->handle . '-SuperTable|', 'label' => $field->name . '(ST)'];
                foreach ($blockTypeFields as $blockTypeField) {
                    if (get_class($blockTypeField) == 'craft\fields\Table') {
                        $containers[] = ['value' => $field->handle . '-SuperTable|' . $blockTypeField->handle . '-Table', 'label' => $field->name . '(ST) | ' . $blockTypeField->name . ' (T)'];
                    }
                }
            }
        }
        */
        return $containers;
    }

    /**
     * Possible craft field types for WordPress types
     *
     * @param string $fieldType
     * @return array
     */
    public static function convertTo(string $fieldType = null): array
    {
        switch ($fieldType) {
            case 'boolean':
                $convertTo = [
                    '' => 'select one',
                    'craft\fields\Lightswitch' => 'lightswitch',
                ];
                break;
            case 'email':
                $convertTo = ['' => 'select one', 'craft\fields\Email' => 'email'];
                break;
            case 'category':
            case 'tag':
            case 'categories block':
            case 'post-term block':
            case 'tag-cloud block':
                $convertTo = [
                    '' => 'select one',
                    'craft\fields\Categories' => 'categories',
                    'craft\fields\Tags' => 'tags',
                    'craft\fields\Entries' => 'entry',
                ];
                break;
            case 'datetime':
                $convertTo = [
                    '' => 'select one',
                    'craft\fields\Date' => 'date/time',
                    'craft\fields\Time' => 'time',
                ];
                break;
            case 'date_time_part':
                $convertTo = [
                    '' => 'select one',
                    'craft\fields\Date' => 'date/time',
                    'craft\fields\Time' => 'time',
                ];
                break;
            case 'file':
                $convertTo = [
                    '' => 'select one',
                    'craft\fields\Assets' => 'asset',
                    'craft\fields\PlainText' => 'plain text',
                    'craft\fields\Url' => 'url',
                ];
                break;
            case 'image':
            case 'audio block':
            case 'image block':
            case 'video block':
            case 'gallery block':
                $convertTo = [
                    '' => 'select one',
                    'craft\fields\Assets' => 'asset',
                    'craft\fields\PlainText' => 'plain text',
                    'craft\fields\Url' => 'url',
                ];
                break;
                // one line url
            case 'url':
                $convertTo = ['' => 'select one', 'craft\fields\Url' => 'url'];
                // text and url
                break;
            case 'link':
                $convertTo = ['' => 'select one', 'craft\fields\Url' => 'url'];
                break;
            case 'link text':
                $convertTo = ['' => 'select one', 'craft\fields\PlainText' => 'plain text'];
                break;
            case 'link URL':
                $convertTo = [
                    '' => 'select one',
                    'craft\fields\Url' => 'url',
                    'craft\fields\Entries' => 'entry',
                ];
                break;
            case 'text':
                $convertTo = [
                    '' => 'select one',
                    'craft\ckeditor\Field' => 'ckeditor',
                    'craft\fields\PlainText' => 'plain text',
                    'craft\redactor\Field' => 'redactor',
                ];
                break;
            case 'timepart':
                $convertTo = [
                    '' => 'select one',
                    'craft\fields\Time' => 'time',
                ];
                break;
            case 'timestamp':
                $convertTo = ['' => 'select one', 'craft\fields\Date' => 'date/time'];
                break;
            case 'code block':
            case 'embed block':
            case 'heading block':
            case 'list block':
            case 'navigation block':
            case 'paragraph':
            case 'paragraph block':
            case 'quote block':
            case 'table block':
            case 'unknown block':
            case 'verse block':
                $convertTo = [
                    '' => 'select one',
                    'craft\fields\PlainText' => 'plain text',
                    'craft\ckeditor\Field' => 'ckeditor',
                    'craft\redactor\Field' => 'redactor',
                ];
                break;
            case 'list':
                $convertTo = [
                    '' => 'select one',
                    'craft\ckeditor\Field' => 'ckeditor',
                    'craft\fields\PlainText' => 'plain text',
                    'craft\redactor\Field' => 'redactor',
                ];
                break;
            case 'acf field':
                $convertTo = [
                    '' => 'select one',
                    'craft\fields\Assets' => 'asset',
                    'craft\fields\Categories' => 'categories',
                    'craft\fields\Checkboxes' => 'checkbox',
                    'craft\fields\Color' => 'color',
                    'craft\ckeditor\Field' => 'ckeditor',
                    'craft\fields\Date' => 'date/time',
                    'craft\fields\Dropdown' => 'drop down',
                    'craft\fields\Entries' => 'entry',
                    //'entries/medias' => 'entries/medias',
                    'craft\fields\Number' => 'number',
                    'craft\fields\MultiSelect' => 'multi select',
                    'craft\fields\Lightswitch' => 'lightswitch',
                    'craft\fields\PlainText' => 'plain text',
                    'craft\redactor\Field' => 'redactor',
                    'craft\fields\RadioButtons' => 'radio button',
                    'craft\fields\Tags' => 'tags',
                    'craft\fields\Time' => 'time',
                    'craft\fields\Url' => 'url',
                    'craft\fields\Users' => 'users',
                ];
                break;
            default:
                $convertTo = ['' => 'select one'];
                break;
        }
        return $convertTo;
    }

    /**
     * create views items for mapping table
     *
     * @param View $view
     * @param array $variables
     * @return array
     */
    public static function View(View $view, array $variables): array
    {
        //$variables['containers'] = [['value' => '', 'label' => 'select one container (Matrix/SuperTable/Table)']];
        $variables['containers'] = [['value' => '', 'label' => 'select one container (Matrix/Table)']];

        $view->startJsBuffer();
        $variables['createTable'] = $view->renderTemplate('migrate-from-wordpress/_createTable');
        $variables['createTableJs'] = $view->clearJsBuffer(false);

        $view->startJsBuffer();
        $variables['newTable'] = $view->renderTemplate('migrate-from-wordpress/_table', [
            'createTable' => $variables['createTable'],
            'createTableJs' => $variables['createTableJs'],
        ]);
        $variables['newTableJs'] = $view->clearJsBuffer(false);

        $view->startJsBuffer();
        $variables['createMatrix'] = $view->renderTemplate('migrate-from-wordpress/_createMatrix');
        $variables['createMatrixJs'] = $view->clearJsBuffer(false);

        $view->startJsBuffer();
        $variables['createBlockType'] = $view->renderTemplate('migrate-from-wordpress/_createBlockType');
        $variables['createBlockTypeJs'] = $view->clearJsBuffer(false);

        $view->startJsBuffer();
        $variables['newMatrix'] = $view->renderTemplate('migrate-from-wordpress/_matrix', [
            'createMatrix' => $variables['createMatrix'],
            'createMatrixJs' => $variables['createMatrixJs'],
            'createBlockType' => $variables['createBlockType'],
            'createBlockTypeJs' => $variables['createBlockTypeJs'],
            'createTable' => $variables['createTable'],
            'createTableJs' => $variables['createTableJs'],
        ]);
        $variables['newMatrixJs'] = $view->clearJsBuffer(false);

        $view->startJsBuffer();
        $variables['createSuperTable'] = $view->renderTemplate('migrate-from-wordpress/_createSuperTable');
        $variables['createSuperTableJs'] = $view->clearJsBuffer(false);

        $view->startJsBuffer();
        $variables['newSuperTable'] = $view->renderTemplate('migrate-from-wordpress/_supertable', [
            'createSuperTable' => $variables['createSuperTable'],
            'createSuperTableJs' => $variables['createSuperTableJs'],
            'createTable' => $variables['createTable'],
            'createTableJs' => $variables['createTableJs'],
        ]);
        $variables['newSuperTableJs'] = $view->clearJsBuffer(false);

        /*
        $view->startJsBuffer();
        $variables['newContainer'] = $view->renderTemplate('migrate-from-wordpress/_text');
        $variables['newContainerJs'] = $view->clearJsBuffer(false);
        */

        $view->startJsBuffer();
        $variables['createField'] = $view->renderTemplate('migrate-from-wordpress/_createField');
        $variables['createFieldJs'] = $view->clearJsBuffer(false);

        $variables['craftField'] = [
            ['value' => '', 'label' => 'Select/Create field'],
        ];

        $volumes['volumes'] = [];
        foreach (Craft::$app->volumes->getAllVolumes()  as $volumeItem) {
            $volume['value'] = $volumeItem->id;
            $volume['label'] = $volumeItem->name;
            $volumes['volumes'][] = $volume;
        }
        $view->startJsBuffer();
        $variables['createAssetField'] = $view->renderTemplate('migrate-from-wordpress/_createAssetField', $volumes);
        $variables['createAssetFieldJs'] = $view->clearJsBuffer(false);

        //Tag field
        $tags['tags'] = [];
        foreach (Craft::$app->tags->getAllTagGroups()  as $tagItem) {
            $tag['value'] = $tagItem->id;
            $tag['label'] = $tagItem->name;
            $tags['tags'][] = $tag;
        }
        $view->startJsBuffer();
        $variables['createTagField'] = $view->renderTemplate('migrate-from-wordpress/_createTagField', $tags);
        $variables['createTagFieldJs'] = $view->clearJsBuffer(false);

        //categoryField
        $categories['categories'] = [];
        foreach (Craft::$app->categories->getAllGroups()  as $categoryItem) {
            $category['value'] = $categoryItem->id;
            $category['label'] = $categoryItem->name;
            $categories['categories'][] = $category;
        }
        $view->startJsBuffer();
        $variables['createCategoryField'] = $view->renderTemplate('migrate-from-wordpress/_createCategoryField', $categories);
        $variables['createCategoryFieldJs'] = $view->clearJsBuffer(false);

        return $variables;
    }

    /**
     * Check user migration status
     *
     * @return void
     */
    public static function hookCheckUserConvert()
    {
        $cpTriggerUrl = Craft::getAlias('@web') . '/' . Craft::$app->getConfig()->getGeneral()->cpTrigger;
        $cache = Craft::$app->getCache();
        $userConvertCacheKey = 'migrate-from-wordpress-convert-status-user';
        $userConvertAddress = $cpTriggerUrl . '/migrate-from-wordpress/users/migrate';
        $userCacheValue = $cache->get($userConvertCacheKey);
        Craft::$app->view->hook($userConvertCacheKey, function() use ($userCacheValue, $userConvertAddress) {
            if ($userCacheValue == 'process') {
                return "<font color='green'> Users are converted</font>";
            } elseif ($userCacheValue == 'feed') {
                return "<font color='green'> Users are waiting for feed run</font>";
            } else {
                return "<font color='red'>Attention!: <a href='" . $userConvertAddress . "'>Users are not converted yet</a></font>";
            }
        });
    }

    /**
     * Convert wordpress URI to Craft URI
     *
     * @param mixed $menuItem
     * @return array
     */
    public static function convertWordPressUri(mixed $menuItem): array
    {
        $url = $menuItem->url;
        $navType = null;
        $navElementId = null;
        $wordpressUUID = null;
        $uri = null;

        $wordpressLanguageSettings = MigrateFromWordPressPlugin::$plugin->settings->wordpressLanguageSettings;
        $tagBase = MigrateFromWordPressPlugin::$plugin->settings->tagBase;
        $categoryBase = MigrateFromWordPressPlugin::$plugin->settings->categoryBase;

        foreach ($wordpressLanguageSettings as $key => $wordpressLanguageSetting) {
            $wordpressURL = $wordpressLanguageSetting['wordpressURL'];
            $uri = str_replace($wordpressURL, "", $url);
        }

        $wordpressFileId = Craft::$app->fields->getFieldByHandle('wordpressFileId');
        $wordpressPostId = Craft::$app->fields->getFieldByHandle('wordpressPostId');
        $wordpressTermId = Craft::$app->fields->getFieldByHandle('wordpressTermId');
        $wordpressUUID = Craft::$app->fields->getFieldByHandle('wordpressUUID');

        if (($menuItem->object == 'category' || $menuItem->object == 'post' || $menuItem->object == 'page') && $menuItem->object_id) {
            $objectId = $menuItem->object_id;
            //search for term id in entries ,tags and categories
            $elementTypes = ['craft\elements\Category', 'craft\elements\Entry'];
            foreach ($elementTypes as $key => $elementType) {
                $record = $elementType::find()->wordpressUUID($objectId)->one();
                if ($record) {
                    if ($record->uri) {
                        $uri = $record->uri;
                        if ($elementType == 'craft\elements\Entry') {
                            $navType = 'craft\elements\Entry';
                            $navElementId = $record->id;
                            $wordpressUUID = $record->wordpressUUID;
                        } else {
                            $navType = 'craft\elements\Category';
                            $navElementId = $record->id;
                            $wordpressUUID = $record->wordpressUUID;
                        }
                    }
                    break;
                }
            }
        } elseif ($wordpressTermId && (strpos($uri, $tagBase) === 0 || strpos($uri, $categoryBase) === 0)) {
            if (strpos($uri, $tagBase) === 0) {
                $termId = str_replace($tagBase, "", $uri);
            } elseif (strpos($uri, $categoryBase) === 0) {
                $termId = str_replace($categoryBase, "", $uri);
            } else {
                throw new ServerErrorHttpException('undefined term id');
            }
            //search for term id in entries ,tags and categories
            $elementTypes = ['craft\elements\Tag', 'craft\elements\Category', 'craft\elements\Entry'];
            foreach ($elementTypes as $key => $elementType) {
                $record = $elementType::find()->wordpressTermId($termId)->one();
                if ($record) {
                    if ($record->uri) {
                        $uri = $record->uri;
                        if ($elementType == 'craft\elements\Entry') {
                            $navType = 'craft\elements\Entry';
                            $navElementId = $record->id;
                            $wordpressUUID = $record->wordpressUUID;
                        } elseif ($elementType == 'craft\elements\Category') {
                            $navType = 'craft\elements\Category';
                            $navElementId = $record->id;
                            $wordpressUUID = $record->wordpressUUID;
                        }
                    }
                    break;
                }
            }
        } else {
            // try to match menu url with migrated pages, posts and medias
            $fileIds = json_decode(Craft::$app->cache->get('migrate-from-wordpress-files-id-and-url'), true);
            // TODO: currently we search for absolute URL, also search for relative links
            if (isset($fileIds[$url]) && isset($wordpressFileId)) {
                $fileId = $fileIds[$url];
                $asset = Asset::find()->wordpressFileId($fileId)->siteId('*')->one();
                if ($asset) {
                    $navType = 'craft\elements\Asset';
                    $navElementId = $asset->id;
                    $wordpressUUID = $asset->wordpressUUID;
                }
            }
            $contentIds = json_decode(Craft::$app->cache->get('migrate-from-wordpress-pages-posts-id-and-url'), true);
            if (isset($contentIds[$url]) && isset($wordpressPostId)) {
                $contentId = $contentIds[$url];
                $entry = Entry::find()->wordpressPostId($contentId)->siteId('*')->one();
                if ($entry) {
                    $navType = 'craft\elements\Entry';
                    $navElementId = $entry->id;
                    $wordpressUUID = $entry->wordpressPostId;
                }
            }
        }
        return array($uri, $navType, $navElementId, $wordpressUUID);
    }


    /**
     * Check if WordPress REST API is available
     *
     * @return bool
     */
    public static function checkRestAPI(): bool
    {
        $wordpressRestApiURL = MigrateFromWordPressPlugin::$plugin->settings->wordpressURL . '/' .
            MigrateFromWordPressPlugin::$plugin->settings->wordpressRestApiEndpoint;
        $address = $wordpressRestApiURL;
        $response = Curl::sendToRestAPI($address . '/settings');
        $response = json_decode($response);
        if (!$response || (isset($response->code) && $response->code == 'rest_forbidden')) {
            return false;
        }
        return true;
    }

    /**
     * Analyze Gutenberg body
     *
     * @param string $content
     * @param bool $mergeSameBlocks
     * @return array
     */
    public static function analyzeGutenberg(string $content, bool $mergeSameBlocks = false): array
    {
        $blocks = [];
        $crawler = new Crawler($content);
        foreach ($crawler->filter('html body')->children() as $domElement) {
            $html = $domElement->ownerDocument->saveHTML($domElement);
            $blocks[] = $html;
        }
        $blockText = null;
        $output = [];
        $previousStartWith = null;
        $value = [];
        $blockTypes = [
            'audio block', 'categories block', 'code block', 'embed block', 'heading block', 'image block', 'gallery block', 'list block',
            'navigation block', 'paragraph block', 'post-term block', 'quote block', 'table block',
            'tag-cloud block', 'unknown block', 'verse block', 'video block',
        ];
        $skip = false;
        $blockIndex = 0;
        foreach ($blocks as $block) {
            if ($block != '') {
                $block = trim($block);
                $crawler = new Crawler($block);
                $c = $crawler->filter('html body')->children();
                $node = $c->getNode(0);
                $nodeName = $node->nodeName;
                $nodeClasses = $c->attr('class');
                $nodeClassesArray = explode(' ', $nodeClasses);
                if (is_null($nodeClasses)) {
                    if ($nodeName == 'p') {
                        if ($mergeSameBlocks && ($previousStartWith == 'paragraph block' || !$previousStartWith)) {
                            $blockText = $blockText . '<br>' . $block;
                            $skip = true;
                        } else {
                            $skip = false;
                        }
                        $currentBlockType = 'paragraph block';
                    } elseif ($nodeName == 'h2' || $nodeName == 'heading') {
                        if ($mergeSameBlocks && ($previousStartWith == 'heading block' || !$previousStartWith)) {
                            $blockText = $blockText . '<br>' . $block;
                            $skip = true;
                        } else {
                            $skip = false;
                        }
                        $currentBlockType = 'heading block';
                    } else {
                        $skip = false;
                        $currentBlockType = 'unknown block';
                    }
                } elseif (in_array('wp-block-code', $nodeClassesArray)) {
                    if ($mergeSameBlocks && ($previousStartWith == 'code block' || !$previousStartWith)) {
                        $blockText = $blockText . '<br>' . $block;
                        $skip = true;
                    } else {
                        $skip = false;
                    }
                    $currentBlockType = 'code block';
                } elseif (in_array('wp-block-embed', $nodeClassesArray)) {
                    if ($mergeSameBlocks && ($previousStartWith == 'embed block' || !$previousStartWith)) {
                        $blockText = $blockText . '<br>' . $block;
                        $skip = true;
                    } else {
                        $skip = false;
                    }
                    $currentBlockType = 'embed block';
                } elseif (in_array('wp-block-image', $nodeClassesArray)) {
                    $c = $crawler->filter('html body img');
                    $block = $c->attr('src');
                    $skip = false;
                    $currentBlockType = 'image block';
                } elseif (in_array('wp-block-gallery', $nodeClassesArray)) {
                    $block = $crawler->filter('html body img')->each(function(Crawler $c) {
                        return $c->attr('src');
                    });
                    $skip = false;
                    $currentBlockType = 'gallery block';
                } elseif (in_array('wp-block-table', $nodeClassesArray)) {
                    if ($mergeSameBlocks && ($previousStartWith == 'table block' || !$previousStartWith)) {
                        $blockText = $blockText . '<br>' . $block;
                        $skip = true;
                    } else {
                        $skip = false;
                    }
                    $currentBlockType = 'table block';
                } elseif (in_array('wp-block-navigation', $nodeClassesArray)) {
                    if ($mergeSameBlocks && ($previousStartWith == 'navigation block' || !$previousStartWith)) {
                        $blockText = $blockText . '<br>' . $block;
                        $skip = true;
                    } else {
                        $skip = false;
                    }
                    $currentBlockType = 'navigation block';
                } elseif (in_array('wp-block-tag-cloud', $nodeClassesArray)) {
                    $block = $crawler->filter('html body a')->each(function(Crawler $c) {
                        return $c->attr('href');
                    });
                    $skip = false;
                    $currentBlockType = 'tag-cloud block';
                } elseif (in_array('wp-block-post-term', $nodeClassesArray)) {
                    $block = $crawler->filter('html body a')->each(function(Crawler $c) {
                        return $c->text();
                    });
                    $skip = false;
                    $currentBlockType = 'post-term block';
                } elseif (in_array('wp-block-categories', $nodeClassesArray)) {
                    $block = $crawler->filter('html body a')->each(function(Crawler $c) {
                        return $c->attr('href');
                    });
                    $skip = false;
                    $currentBlockType = 'categories block';
                } elseif (in_array('wp-block-verse', $nodeClassesArray)) {
                    if ($mergeSameBlocks && ($previousStartWith == 'verse block' || !$previousStartWith)) {
                        $blockText = $blockText . '<br>' . $block;
                        $skip = true;
                    } else {
                        $skip = false;
                    }
                    $currentBlockType = 'verse block';
                } elseif (in_array('wp-block-quote', $nodeClassesArray)) {
                    if ($mergeSameBlocks && ($previousStartWith == 'quote block' || !$previousStartWith)) {
                        $blockText = $blockText . '<br>' . $block;
                        $skip = true;
                    } else {
                        $skip = false;
                    }
                    $currentBlockType = 'quote block';
                } elseif (in_array('wp-block-audio', $nodeClassesArray)) {
                    $block = $crawler->filter('html body audio')->each(function(Crawler $c) {
                        return $c->attr('src');
                    });
                    $skip = false;
                    $currentBlockType = 'audio block';
                } elseif (in_array('wp-block-video', $nodeClassesArray)) {
                    $block = $crawler->filter('html body video')->each(function(Crawler $c) {
                        return $c->attr('src');
                    });
                    $skip = false;
                    $currentBlockType = 'video block';
                } elseif (str_starts_with($block, '<p>')) {
                    if ($mergeSameBlocks && ($previousStartWith == 'paragraph' || !$previousStartWith)) {
                        $blockText = $blockText . '<br>' . $block;
                        $skip = true;
                    } else {
                        $skip = false;
                    }
                    $currentBlockType = 'paragraph block';
                } elseif (str_starts_with($block, '<ul>')) {
                    if ($mergeSameBlocks && ($previousStartWith == 'list' || !$previousStartWith)) {
                        $blockText = $blockText . '<br>' . $block;
                        $skip = true;
                    } else {
                        $skip = false;
                    }
                    $currentBlockType = 'list block';
                } else {
                    $skip = false;
                    $currentBlockType = 'unknown block';
                }
                if (!$skip) {
                    if (!$mergeSameBlocks) {
                        foreach ($blockTypes as $blockType) {
                            if ($blockType == $currentBlockType) {
                                $blockIndex++;
                                $value[$blockIndex][$blockType][] = $block;
                            }
                        }
                    } else {
                        foreach ($blockTypes as $blockType) {
                            if ($blockType == $previousStartWith) {
                                $value[$blockIndex][$blockType][] = $blockText;
                                $blockIndex++;
                            }
                        }
                    }
                    $blockText = $block;
                }
                $previousStartWith = $currentBlockType;
            }
        }

        if (isset($currentBlockType) && $mergeSameBlocks) {
            $value[$blockIndex][$currentBlockType][] = $blockText;
        }

        foreach ($blockTypes as $key => $blockType) {
            $output['fields'][$blockType]['config']['parent'] = 'body';
            $output['fields'][$blockType]['config']['type'] = $blockType;
            $output['fields'][$blockType]['config']['label'] = $blockType;
        }
        $output['value'] = $value;

        return $output;
    }

    /**
     * Analyze ACF fields
     *
     * @param object $item
     * @param array $content
     * @return array
     */
    public static function analyzeACF(object $item, array $content): array
    {
        $acf = get_object_vars($item->acf);
        foreach ($acf as $acfIndex => $acfValue) {
            if (is_object($acfValue)) {
                if (isset($acfValue->title) && isset($acfValue->url)) {
                    $type = 'acf link';
                } else {
                    $type = 'acf group';
                }
                $content['fields']['acf_' . $acfIndex]['config']['type'] = $type;
                $content['fields']['acf_' . $acfIndex]['config']['label'] = $acfIndex;
                $fields = (array) $acfValue;
                foreach ($fields as $fieldKey => $field) {
                    $fieldType = 'acf field';
                    if ($type == 'acf link') {
                        if ($fieldKey == 'title') {
                            $fieldType = 'link text';
                        } elseif ($fieldKey == 'url') {
                            $fieldType = 'link URL';
                        }
                    }
                    $content['fields']['acf_' . $acfIndex]['fields']['acf_' . $acfIndex . '_' . $fieldKey]['config']['type'] = $fieldType;
                    $content['fields']['acf_' . $acfIndex]['fields']['acf_' . $acfIndex . '_' . $fieldKey]['config']['label'] = $fieldKey;
                    $content['fields']['acf_' . $acfIndex]['fields']['acf_' . $acfIndex . '_' . $fieldKey]['value'] = $field;
                }
            } else {
                //add acf- to array index - there is a possibility there is an attribute with same name
                $content['fields']['acf_' . $acfIndex]['config']['type'] = 'acf field';
                $content['fields']['acf_' . $acfIndex]['config']['name'] = $acfIndex;
                $content['fields']['acf_' . $acfIndex]['config']['label'] = $acfIndex;
                $content['fields']['acf_' . $acfIndex]['value'] = $acfValue;
            }
        }
        return $content;
    }

    /**
     * Regenerate token used in feedme
     *
     * @return string
     */
    public static function regenerateFeedToken(): string
    {
        $oldToken = Craft::$app->getCache()->get('migrate-from-wordpress-protect-feed-values');
        if (MigrateFromWordPressPlugin::$plugin->getSettings()->allowReusingToken) {
            // Don't let reusing old token on non dev environment anyway
            if (Craft::$app->config->env == 'dev') {
                $newToken = $oldToken;
            }
        }

        // If new token is not created yet
        if (!isset($newToken)) {
            Craft::$app->getCache()->set('migrate-from-wordpress-token-regenerate', 'wait', 0, new TagDependency(['tags' => 'migrate-from-wordpress']));
            $feedRecords = FeedRecord::find()->all();
            $newToken = Craft::$app->getSecurity()->generateRandomString();
            foreach ($feedRecords as $feedRecord) {
                /** @var FeedRecord $feedRecord */
                $feedUrl = $feedRecord->feedUrl;
                $newUrl = str_replace($oldToken, $newToken, $feedUrl);
                $feedRecord->feedUrl = $newUrl;
                if (!$feedRecord->save()) {
                    throw new ServerErrorHttpException('there is a problem with feed saving');
                }
            }
            Craft::$app->getCache()->set('migrate-from-wordpress-protect-feed-values', $newToken, 0, new TagDependency(['tags' => 'migrate-from-wordpress']));
            Craft::$app->getCache()->set('migrate-from-wordpress-token-regenerate', 'finished', 0, new TagDependency(['tags' => 'migrate-from-wordpress']));
        }
        return $newToken;
    }
}
