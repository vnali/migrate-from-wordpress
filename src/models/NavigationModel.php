<?php

namespace vnali\migratefromwordpress\models;

use Craft;
use craft\base\Model;

class NavigationModel extends Model
{
    /**
     * @var string
     */
    public $convertNavigationTo;

    /**
     * @var int
     */
    public $entryTypeId;

    /**
     * @var int
     */
    public $navId;

    /**
     * @var int
     */
    public $sectionId;


    public function init(): void
    {
        parent::init();
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['convertNavigationTo'], 'required', 'skipOnEmpty' => false, 'on' => 'convert-navigation'];
        $rules[] = [['convertNavigationTo'], 'in', 'range' => ['entry', 'navigation'], 'on' => ['convert-navigation']];
        $rules[] = [['convertNavigationTo'], function($attribute, $params, $validator) {
            if ($this->convertNavigationTo == 'navigation') {
                if (!Craft::$app->plugins->isPluginEnabled('navigation')) {
                    $this->addError($attribute, 'Navigation plugin is not installed/enabled.');
                }
            }

            if ($this->convertNavigationTo == 'navigation' && Craft::$app->plugins->isPluginEnabled('navigation') && !$this->navId) {
                $this->addError('convertNavigationTo', 'Target nav is not selected.');
            }

            if ($this->convertNavigationTo == 'entry' && (!$this->sectionId || !$this->entryTypeId)) {
                $this->addError('convertNavigationTo', 'Section or entry type is not selected.');
            }
        }, 'skipOnEmpty' => true, 'on' => ['convert-navigation']];
        return $rules;
    }
}
