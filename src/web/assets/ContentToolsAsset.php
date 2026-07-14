<?php

namespace bensomething\contenttools\web\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class ContentToolsAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [
        CpAsset::class,
    ];

    public $js = [
        'content-tools.js',
    ];

    public $css = [
        'content-tools.css',
    ];
}
