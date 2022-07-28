<?php

namespace vnali\migratefromwordpress\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Asset Bundle used on plugin setting page
 */
class SettingPageAsset extends AssetBundle
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

        parent::init();
    }
}
