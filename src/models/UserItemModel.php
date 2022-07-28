<?php

namespace vnali\migratefromwordpress\models;

use Craft;
use craft\base\Model;

use vnali\migratefromwordpress\helpers\FieldHelper;
use vnali\migratefromwordpress\helpers\SiteHelper;

class UserItemModel extends Model
{
    /**
     * @var string
     */
    public $wordpressLanguage;

    /**
     * @var string
     */
    public $wordpressFullNameField;

    /**
     * @var string
     */
    public $wordpressUserPictureField;

    /**
     * @var array
     */
    private $_fieldDefinitions;

    public function init(): void
    {
        $this->_fieldDefinitions = json_decode(Craft::$app->getCache()->get('migrate-from-wordpress-user-fields'), true);
        parent::init();
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['wordpressLanguage'], 'in', 'range' => array_keys(SiteHelper::availableWordPressLanguages())];
        $rules[] = [
            ['wordpressFullNameField'], 'in',
            'range' => array_keys(FieldHelper::filterFieldsByType($this->_fieldDefinitions, 'plain text')),
        ];
        $rules[] = [
            ['wordpressUserPictureField'], 'in',
            'range' => array_keys(FieldHelper::filterFieldsByType($this->_fieldDefinitions, 'asset')),
        ];
        return $rules;
    }
}
