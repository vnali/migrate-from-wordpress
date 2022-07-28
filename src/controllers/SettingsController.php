<?php

namespace vnali\migratefromwordpress\controllers;

use Craft;
use craft\web\Controller;

use craft\web\UrlManager;
use vnali\migratefromwordpress\helpers\SiteHelper;
use vnali\migratefromwordpress\MigrateFromWordPress as MigrateFromWordPressPlugin;

use yii\web\NotFoundHttpException;
use yii\web\Response;

class SettingsController extends Controller
{
    /**
     * @inheritDoc
     */
    public function init(): void
    {
        parent::init();
    }

    /**
     * Plugin settings
     *
     * @return Response The rendered result
     */
    public function actionPlugin(): Response
    {
        $settings = MigrateFromWordPressPlugin::$plugin->getSettings();
        $wordpressLanguages = SiteHelper::availableWordPressLanguages();
        return $this->renderTemplate(
            'migrate-from-wordpress/_settings',
            [
                'settings' => $settings,
                'wordpressLanguages' => $wordpressLanguages,
                'fullPageForm' => true,
            ]
        );
    }

    /**
     * Saves a plugin’s settings.
     *
     * @return Response|null
     * @throws NotFoundHttpException if the requested plugin cannot be found
     * @throws \yii\web\BadRequestHttpException
     * @throws \craft\errors\MissingComponentException
     */
    public function actionSavePluginSettings()
    {
        $this->requirePostRequest();
        $pluginHandle = Craft::$app->getRequest()->getRequiredBodyParam('pluginHandle');
        $settings = Craft::$app->getRequest()->getBodyParam('settings', []);
        $plugin = Craft::$app->getPlugins()->getPlugin($pluginHandle);

        if ($plugin === null) {
            throw new NotFoundHttpException('Plugin not found');
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
            Craft::$app->getSession()->setError(Craft::t('migrate-from-wordpress', "Couldn't save plugin settings."));

            // Send the plugin back to the template
            /** @var UrlManager $urlManager */
            $urlManager = Craft::$app->getUrlManager();
            $urlManager->setRouteParams([
                'plugin' => $plugin,
            ]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('migrate-from-wordpress', 'Plugin settings saved.'));

        return $this->redirectToPostedUrl();
    }
}
