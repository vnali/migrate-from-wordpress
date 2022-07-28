<?php

namespace vnali\migratefromwordpress\models;

use craft\base\Model;

use craft\validators\SiteIdValidator;
use vnali\migratefromwordpress\helpers\SiteHelper;

class SiteModel extends Model
{
    /**
     * @var int
     */
    public $convert;

    /**
     * @var array
     */
    public $converts = [];

    /**
     * @var int
     */
    public $craftSiteId;

    /**
     * @var string
     */
    public $wordpressLanguage;

    public function init(): void
    {
        parent::init();
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['convert'], 'in', 'range' => ['0', '1']];
        $rules[] = [['wordpressLanguage'], 'in',
            'range' => array_keys(SiteHelper::availableWordPressLanguages()),
            'message' => $this->wordpressLanguage . '-' . json_encode(SiteHelper::availableWordPressLanguages()),
        ];
        $rules[] = [['wordpressLanguage'], function($attribute, $params, $validator) {
            $enableForMigrationLanguages = SiteHelper::availableWordPressLanguages();
            if (!isset($enableForMigrationLanguages[$this->wordpressLanguage]) || !$enableForMigrationLanguages[$this->wordpressLanguage]['enableForMigration']) {
                $this->addError($attribute, $this->wordpressLanguage . ' language is not valid');
            }
        }];
        $rules[] = [['craftSiteId'], SiteIdValidator::class];
        $rules[] = [['convert'], function($attribute, $params, $validator) {
            $hasConvert = false;
            foreach ($this->converts as $convert) {
                if ($convert == '1') {
                    $hasConvert = true;
                    break;
                }
            }
            if (!$hasConvert) {
                $this->addError($attribute, 'select at least one language');
            }
        }, 'skipOnEmpty' => false, 'on' => 'lastLanguage'];
        return $rules;
    }
}
