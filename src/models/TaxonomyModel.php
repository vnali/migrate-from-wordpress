<?php

namespace vnali\migratefromwordpress\models;

use craft\base\Model;

class TaxonomyModel extends Model
{
    /**
     * @var int|null
     */
    public $sectionId;

    /**
     * @var int|null
     */
    public $tagId;

    /**
     * @var int|null
     */
    public $categoryId;

    /**
     * @var string
     */
    public $tagGroupHandle;

    /**
     * @var string
     */
    public $categoryGroupHandle;

    public function init(): void
    {
        parent::init();
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = ['sectionId', function($attribute, $params, $validator) {
            if (!$this->sectionId && !$this->tagId && !$this->categoryId && !$this->tagGroupHandle && !$this->categoryGroupHandle) {
                $this->addError('*', 'you should specify one of sections or tags or categories.');
            }
        }, 'skipOnEmpty' => false, 'on' => 'convert-taxonomy'];
        return $rules;
    }
}
