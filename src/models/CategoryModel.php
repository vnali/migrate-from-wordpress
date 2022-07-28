<?php

namespace vnali\migratefromwordpress\models;

use craft\base\Model;
use craft\records\CategoryGroup as CategoryGroupRecord;
use craft\records\Field as FieldRecord;

class CategoryModel extends Model
{
    /**
     * @var string
     */
    public $categoryGroupName;

    /**
     * @var int
     */
    public $categoryId;

    /**
     * @var string
     */
    public $categoryGroupHandle;

    /**
     * @var string
     */
    public $categoryFieldHandle;

    public function init(): void
    {
        parent::init();
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = ['categoryGroupName', 'required', 'when' => function($model) {
            return $model->categoryId == '-1';
        }];
        $rules[] = [['categoryId'], 'exist', 'targetClass' => CategoryGroupRecord::class, 'targetAttribute' => ['categoryId' => 'id'], 'when' => function($model) {
            return $model->categoryId != '-1';
        }, 'on' => 'convert-taxonomy'];
        $rules[] = [['categoryGroupHandle'], 'unique', 'targetClass' => CategoryGroupRecord::class, 'targetAttribute' => ['categoryGroupHandle' => 'handle'], 'on' => 'convert-taxonomy'];
        $rules[] = [['categoryGroupHandle'], 'string', 'max' => 255, 'on' => 'convert-taxonomy'];
        $rules[] = [['categoryFieldHandle'], 'unique', 'targetClass' => FieldRecord::class, 'targetAttribute' => ['categoryFieldHandle' => 'handle'], 'on' => 'convert-taxonomy'];
        return $rules;
    }
}
