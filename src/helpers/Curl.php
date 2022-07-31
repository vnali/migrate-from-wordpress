<?php

namespace vnali\migratefromwordpress\helpers;

use vnali\migratefromwordpress\MigrateFromWordPress as MigrateFromWordPressPlugin;

class Curl
{
    /**
     * Get data from Rest API via CURL
     *
     * @param string $address
     * @return string
     */
    public static function sendToRestAPI(string $address): string
    {
        $user = MigrateFromWordPressPlugin::$plugin->settings->wordpressAccountUsername;
        $password = MigrateFromWordPressPlugin::$plugin->settings->wordpressPassword;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_POST, 0);
        curl_setopt($ch, CURLOPT_URL, $address);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPGET, 1);
        curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $password);
        $content = trim(curl_exec($ch));
        curl_close($ch);
        return $content;
    }
}
