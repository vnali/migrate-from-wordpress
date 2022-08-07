<?php

namespace vnali\migratefromwordpress\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\web\Controller;

use vnali\migratefromwordpress\helpers\FieldHelper;
use vnali\migratefromwordpress\helpers\GeneralHelper;
use vnali\migratefromwordpress\items\FileItem;
use vnali\migratefromwordpress\items\MenuItem;
use vnali\migratefromwordpress\items\NavigationItem;
use vnali\migratefromwordpress\items\PageItem;
use vnali\migratefromwordpress\items\PostItem;
use vnali\migratefromwordpress\items\TaxonomyItem;
use vnali\migratefromwordpress\items\UserItem;

use Yii;
use yii\web\Response;
use ZipArchive;

class TroubleshootController extends Controller
{
    /**
     * @inheritDoc
     */
    public function init(): void
    {
        parent::init();
    }

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        $response = GeneralHelper::checkRestAPI();
        if (!$response) {
            Craft::$app->getSession()->setError(Craft::t('migrate-from-wordpress', 'Check and save plugin settings first.'));
            $this->redirect('migrate-from-wordpress/settings/plugin');
            return false;
        }

        return parent::beforeAction($action);
    }

    /**
     * List all WordPress items for migrate.
     * @return Response
     */
    public function actionIndex(): Response
    {
        $variable = [];
        $cache = Craft::$app->getCache();
        // Navigation
        $navigations = $cache->get('migrate-from-wordpress-available-navigation-types');
        foreach ($navigations as $navigation) {
            $item = [];
            $item['value'] = 'navigation:' . $navigation['value'];
            $item['label'] = 'navigation: ' . $navigation['label'];
            $variable['items'][] = $item;
        }
        // Menus
        $menus = $cache->get('migrate-from-wordpress-available-menu-types');
        foreach ($menus as $menu) {
            $item = [];
            $item['value'] = 'menu:' . $menu['value'];
            $item['label'] = 'menu: ' . $menu['label'];
            $variable['items'][] = $item;
        }
        // Taxonomies
        $taxonomies = $cache->get('migrate-from-wordpress-available-taxonomy-types');
        foreach ($taxonomies as $taxonomy) {
            $item = [];
            $item['value'] = 'taxonomy:' . $taxonomy;
            $item['label'] = 'taxonomy: ' . $taxonomy;
            $variable['items'][] = $item;
        }
        // Post types
        $posts = $cache->get('migrate-from-wordpress-available-post-types');
        foreach ($posts as $post) {
            $item = [];
            $item['value'] = 'post:' . $post;
            $item['label'] = 'post';
            $variable['items'][] = $item;
        }
        // Page types
        $pages = $cache->get('migrate-from-wordpress-available-page-types');
        foreach ($pages as $page) {
            $item = [];
            $item['value'] = 'page:' . $page;
            $item['label'] = 'page' . $page;
            $variable['items'][] = $item;
        }

        // Users
        $item = [];
        $item['value'] = 'user:';
        $item['label'] = 'user';
        $variable['items'][] = $item;

        // Files
        $item = [];
        $item['value'] = 'file:';
        $item['label'] = 'file';
        $variable['items'][] = $item;

        return $this->renderTemplate('migrate-from-wordpress/_troubleshoot', $variable);
    }

    public function actionGetSampleData()
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $cache = Craft::$app->cache;

        $items = $request->getBodyParam('items');
        if (!$items) {
            Craft::$app->getSession()->setError(Craft::t('migrate-from-wordpress', 'You should select at least one item.'));
            $this->redirectToPostedUrl();
            return false;
        }
        $zip = new ZipArchive();
        $zipFile = '../storage/migrate-from-wordpress-' . StringHelper::UUID() . '.zip';
        if ($zip->open($zipFile, ZipArchive::CREATE)) {
            foreach ($items as $item) {
                $itemParts = explode(':', $item);
                $item = $itemParts[0];
                if ($itemParts[0] == 'taxonomy') {
                    $item = $itemParts[1];
                }
                $itemData = [];
                switch ($item) {
                    case 'file':
                        $itemFile = 'files.txt';
                        $itemRestFile = 'files-REST.txt';
                        $fileItem = new FileItem(1, 1);
                        $itemsRestData = $fileItem->getFileItems();
                        $fieldDefinitions = Craft::$app->cache->get('migrate-from-wordpress-file-fields');
                        $fieldDefinitions = json_decode($fieldDefinitions, true);
                        if ($fieldDefinitions) {
                            list($contents) = $fileItem->getValues();
                            $level = '';
                            foreach ($contents as $content) {
                                $fieldValues = [];
                                FieldHelper::analyzeFieldValues($content, $fieldValues, $level, '', 'file', $fieldDefinitions);
                                if ($fieldValues) {
                                    $itemData = $fieldValues;
                                }
                            }
                        }

                        if (count($itemData) == 0) {
                            $itemData = null;
                        }
                        break;
                    case 'user':
                        $itemFile = 'users.txt';
                        $itemRestFile = 'users-REST.txt';
                        $userItem = new UserItem(1, 1);
                        $itemsRestData = $userItem->getUserItems();
                        $fieldDefinitions = Craft::$app->cache->get('migrate-from-wordpress-user-fields');
                        $fieldDefinitions = json_decode($fieldDefinitions, true);
                        if ($fieldDefinitions) {
                            list($contents) = $userItem->getValues();
                            $level = '';
                            foreach ($contents as $content) {
                                $fieldValues = [];
                                FieldHelper::analyzeFieldValues($content, $fieldValues, $level, '', 'user', $fieldDefinitions);
                                if ($fieldValues) {
                                    $itemData = $fieldValues;
                                }
                            }
                        }
                        if (count($itemData) == 0) {
                            $itemData = null;
                        }
                        break;
                    case 'page':
                        $itemFile = 'pages.txt';
                        $itemRestFile = 'pages-REST.txt';
                        $pageItem = new PageItem(null, 1, 1, 'en');
                        $itemsRestData = $pageItem->getPageItems();
                        $fieldDefinitions = Craft::$app->cache->get('migrate-from-wordpress-page--fields');
                        $fieldDefinitions = json_decode($fieldDefinitions, true);
                        if ($fieldDefinitions) {
                            list($contents) = $pageItem->getValues();
                            $level = '';
                            foreach ($contents as $content) {
                                $fieldValues = [];
                                FieldHelper::analyzeFieldValues($content, $fieldValues, $level, '', 'page', $fieldDefinitions);
                                if ($fieldValues) {
                                    $itemData = $fieldValues;
                                }
                            }
                        }

                        if (count($itemData) == 0) {
                            $itemData = null;
                        }
                        break;
                    case 'post':
                        $itemFile = 'posts.txt';
                        $itemRestFile = 'posts-REST.txt';
                        $postItem = new PostItem(null, 1, 1, 'en');
                        $itemsRestData = $postItem->getPostItems();
                        $fieldDefinitions = Craft::$app->cache->get('migrate-from-wordpress-post--fields');
                        $fieldDefinitions = json_decode($fieldDefinitions, true);
                        if ($fieldDefinitions) {
                            list($contents) = $postItem->getValues();
                            $itemData = [];
                            $level = '';
                            foreach ($contents as $content) {
                                $fieldValues = [];
                                FieldHelper::analyzeFieldValues($content, $fieldValues, $level, '', 'post', $fieldDefinitions);
                                if ($fieldValues) {
                                    $itemData = $fieldValues;
                                }
                            }
                        }
                        if (count($itemData) == 0) {
                            $itemData = null;
                        }
                        break;
                    case 'tags':
                        $itemFile = 'tags.txt';
                        $itemRestFile = 'tags-REST.txt';
                        $tagItem = new TaxonomyItem('tags', 1, 1);
                        $itemsRestData = $tagItem->getTaxonomyItems();
                        $fieldDefinitions = Craft::$app->cache->get('migrate-from-wordpress-taxonomy-tags-fields');
                        $fieldDefinitions = json_decode($fieldDefinitions, true);
                        if ($fieldDefinitions) {
                            list($contents) = $tagItem->getValues();
                            $itemData = [];
                            $level = '';
                            foreach ($contents as $content) {
                                $fieldValues = [];
                                FieldHelper::analyzeFieldValues($content, $fieldValues, $level, '', 'tags', $fieldDefinitions);
                                if ($fieldValues) {
                                    $itemData = $fieldValues;
                                }
                            }
                        }
                        if (count($itemData) == 0) {
                            $itemData = null;
                        }
                        break;
                    case 'categories':
                        $itemFile = 'categories.txt';
                        $itemRestFile = 'categories-REST.txt';
                        $categoryItem = new TaxonomyItem('categories', 1, 1);
                        $itemsRestData = $categoryItem->getTaxonomyItems();
                        $fieldDefinitions = Craft::$app->cache->get('migrate-from-wordpress-taxonomy-categories-fields');
                        $fieldDefinitions = json_decode($fieldDefinitions, true);
                        if ($fieldDefinitions) {
                            list($contents) = $categoryItem->getValues();
                            $level = '';
                            foreach ($contents as $content) {
                                $fieldValues = [];
                                FieldHelper::analyzeFieldValues($content, $fieldValues, $level, '', 'categories', $fieldDefinitions);
                                if ($fieldValues) {
                                    $itemData = $fieldValues;
                                }
                            }
                        }

                        if (count($itemData) == 0) {
                            $itemData = null;
                        }
                        break;
                    case 'menu':
                        $menus = $cache->get('migrate-from-wordpress-available-menu-types');
                        $itemFile = 'menus-' . $menus[$itemParts[1]]['label'] . '.txt';
                        $itemRestFile = 'menus' . $menus[$itemParts[1]]['label'] . '-REST.txt';
                        $menuItem = new MenuItem($itemParts[1], 1, 1);
                        $itemsRestData = $menuItem->getMenuItems();
                        $fieldDefinitions = Craft::$app->cache->get('migrate-from-wordpress-menu-' . $itemParts[1] . '-fields');
                        $fieldDefinitions = json_decode($fieldDefinitions, true);
                        if ($fieldDefinitions) {
                            list($contents) = $menuItem->getValues();
                            $level = '';
                            foreach ($contents as $content) {
                                $fieldValues = [];
                                FieldHelper::analyzeFieldValues($content, $fieldValues, $level, '', 'menu', $fieldDefinitions);
                                if ($fieldValues) {
                                    $itemData = $fieldValues;
                                }
                            }
                        }
                        if (count($itemData) == 0) {
                            $itemData = null;
                        }
                        break;
                    case 'navigation':
                        $navigations = $cache->get('migrate-from-wordpress-available-navigation-types');
                        $itemFile = 'navigations-' . $navigations[$itemParts[1]]['label'] . '.txt';
                        $itemRestFile = 'navigations-' . $navigations[$itemParts[1]]['label'] . '-REST.txt';
                        $navigationItem = new NavigationItem($itemParts[1], 1, 1);
                        $itemsRestData = $navigationItem->getNavigationItems();
                        $fieldDefinitions = Craft::$app->cache->get('migrate-from-wordpress-navigation-' . $itemParts[1] . '-fields');
                        $fieldDefinitions = json_decode($fieldDefinitions, true);
                        if ($fieldDefinitions) {
                            list($contents) = $navigationItem->getValues();
                            $level = '';
                            foreach ($contents as $content) {
                                $fieldValues = [];
                                FieldHelper::analyzeFieldValues($content, $fieldValues, $level, '', 'navigation', $fieldDefinitions);
                                if ($fieldValues) {
                                    $itemData = $fieldValues;
                                }
                            }
                        }
                        if (count($itemData) == 0) {
                            $itemData = null;
                        }
                        break;
                    default:
                        # code...
                        break;
                }
                $itemRestData = null;
                if (isset($itemsRestData[0])) {
                    $itemRestData = $itemsRestData[0];
                }
                $zip->addFromString($itemFile, json_encode($itemData, JSON_PRETTY_PRINT));
                $zip->addFromString($itemRestFile, json_encode($itemRestData, JSON_PRETTY_PRINT));
            }
        }
        $zip->close();
        return Yii::$app->response->sendFile($zipFile);
    }
}
