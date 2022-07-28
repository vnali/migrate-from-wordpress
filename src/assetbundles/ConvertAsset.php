<?php

namespace vnali\migratefromwordpress\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Asset Bundle used on items migration pages
 */
class ConvertAsset extends AssetBundle
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
            'css/convert.css',
        ];
        
        $this->js = [
            'js/convert.js',
        ];

        parent::init();
    }
}
