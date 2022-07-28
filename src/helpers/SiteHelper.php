<?php

namespace vnali\migratefromwordpress\helpers;

use Craft;

/**
 * Helper for sites, domains, languages used in Craft CMS and WordPress
 */
class SiteHelper
{
    /**
     * Returns WordPress languages
     *
     * @return array
     */
    public static function availableWordPressLanguages(): array
    {
        $language = [];
        $language['id'] = 'en';
        $language['value'] = 'en';
        $language['label'] = 'default';
        $language['enableForMigration'] = true;
        $language['errors'] = [];
        $languages[$language['id']] = $language;
        return $languages;
    }

    /**
     * Get Craft sites
     *
     * @return array
     */
    public static function availableCraftSites(): array
    {
        $variables['sites'] = [];
        $sites = Craft::$app->sites->getAllSites();
        foreach ($sites as $site) {
            $craftSite['value'] = $site->id;
            $craftSite['label'] = $site->name;
            $variables['sites'][] = $craftSite;
        }
        return $variables['sites'];
    }
}
