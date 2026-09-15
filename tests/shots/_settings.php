<?php
/**
 * Craft's Plugins::savePluginSettings() REPLACES plugins.<handle>.settings in project config with
 * exactly the array it is given — it does not merge with what is already stored. Passing a couple
 * of keys therefore silently wipes the credentials. Always send the whole model.
 */
function bee_set_settings(array $overrides): void
{
    $plugin = \justinholtweb\bee\Plugin::getInstance();
    $current = $plugin->getSettings()->toArray();
    Craft::$app->plugins->savePluginSettings($plugin, array_merge($current, $overrides));
    Craft::$app->getProjectConfig()->saveModifiedConfigData();   // console never hits EVENT_AFTER_REQUEST
}
