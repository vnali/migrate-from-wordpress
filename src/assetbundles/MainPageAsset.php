<?php

namespace vnali\migratefromwordpress\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Asset bundle used on default page of plugin
 */
class MainPageAsset extends AssetBundle
{
    /**
     * @inheritDoc
     */
    public function init()
    {
        $this->sourcePath = "@vnali/migratefromwordpress/assetbundles/dist";

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = [
            'css/tailwind3-custom.css',
        ];

        $this->js = [
            'js/main.js',
        ];
        
        parent::init();
    }
}
