<?php

namespace vnali\migratefromwordpress\models;

use Craft;
use craft\base\Model;

class MenuModel extends Model
{
    /**
     * @var string
     */
    public $convertMenuTo;

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
        $rules[] = [['convertMenuTo'], 'required', 'skipOnEmpty' => false, 'on' => 'convert-menu'];
        $rules[] = [['convertMenuTo'], 'in', 'range' => ['entry', 'navigation'], 'on' => ['convert-menu']];
        $rules[] = [['convertMenuTo'], function($attribute, $params, $validator) {
            if ($this->convertMenuTo == 'navigation') {
                if (!Craft::$app->plugins->isPluginEnabled('navigation')) {
                    $this->addError($attribute, 'Navigation plugin is not installed/enabled.');
                }
            }

            if ($this->convertMenuTo == 'navigation' && Craft::$app->plugins->isPluginEnabled('navigation') && !$this->navId) {
                $this->addError('convertMenuTo', 'Target nav is not selected.');
            }

            if ($this->convertMenuTo == 'entry' && (!$this->sectionId || !$this->entryTypeId)) {
                $this->addError('convertMenuTo', 'Section or entry type is not selected.');
            }
        }, 'skipOnEmpty' => true, 'on' => ['convert-menu']];
        return $rules;
    }
}
