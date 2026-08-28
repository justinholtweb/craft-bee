<?php

namespace justinholtweb\bee\web\assets\runtime;

use craft\web\AssetBundle;

/**
 * The front-end runtime. No dependencies, no build step — one file, plain ES5-compatible
 * JavaScript, because it is injected into other people's sites and must not assume a bundler,
 * a framework, or a modern browser.
 */
class RuntimeAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->js = ['bee.js'];

        parent::init();
    }
}
