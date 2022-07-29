<?php

namespace vnali\migratefromwordpress\models;

use Craft;
use craft\base\Model;

use yii\helpers\ArrayHelper;

class EntryModel extends Model
{
    /**
     * @var int
     */
    public $sectionId;

    /**
     * @var int
     */
    public $entryTypeId;

    public function init(): void
    {
        parent::init();
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['sectionId', 'entryTypeId'], 'required', 'skipOnEmpty' => false, 'on' => ['convert-menu', 'convert-navigation', 'convert-page', 'convert-post']];
        $rules[] = [['sectionId'], function($attribute, $params, $validator) {
            $sections = [];
            foreach (Craft::$app->sections->getAllSections() as $section) {
                if ($section->type != 'single') {
                    $sections[] = $section->id;
                }
            }
            if (!in_array($this->$attribute, $sections)) {
                $this->addError($attribute, 'section is not valid.');
            }
        }, 'skipOnEmpty' => true, 'on' => ['convert-menu', 'convert-navigation', 'convert-page', 'convert-post', 'convert-taxonomy']];
        $rules[] = [['entryTypeId'], function($attribute, $params, $validator) {
            if (is_numeric($this->sectionId)) {
                $section = Craft::$app->sections->getSectionById($this->sectionId);
                $entryTypeIds = ArrayHelper::getColumn($section->entryTypes, 'id');
                if (!in_array($this->$attribute, $entryTypeIds)) {
                    $this->addError($attribute, 'entry type is not valid.');
                }
            }
        }, 'skipOnEmpty' => true, 'on' => ['convert-menu', 'convert-navigation', 'convert-page', 'convert-post', 'convert-taxonomy']];
        return $rules;
    }
}
