<?php

namespace bensomething\discontent\web\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class DiscontentAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [
        CpAsset::class,
    ];

    public $js = [
        'discontent.js',
    ];

    public $css = [
        'discontent.css',
    ];
}
