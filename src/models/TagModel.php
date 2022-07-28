<?php

namespace vnali\migratefromwordpress\models;

use craft\base\Model;
use craft\records\Field as FieldRecord;
use craft\records\TagGroup as TagGroupRecord;

class TagModel extends Model
{
    /**
     * @var string
     */
    public $tagGroupName;

    /**
     * @var int|null
     */
    public $tagId;

    /**
     * @var string
     */
    public $tagGroupHandle;

    /**
     * @var string
     */
    public $tagFieldHandle;

    public function init(): void
    {
        parent::init();
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = ['tagGroupName', 'required', 'when' => function($model) {
            return $model->tagId == '-1';
        }];
        $rules[] = ['tagId', 'exist', 'targetClass' => TagGroupRecord::class, 'targetAttribute' => ['tagId' => 'id'], 'when' => function($model) {
            return $model->tagId != '-1';
        }, 'on' => 'convert-taxonomy'];
        $rules[] = ['tagGroupHandle', 'unique', 'targetClass' => TagGroupRecord::class, 'targetAttribute' => ['tagGroupHandle' => 'handle'], 'on' => 'convert-taxonomy'];
        $rules[] = [['tagGroupHandle'], 'string', 'max' => 255, 'on' => 'convert-taxonomy'];
        $rules[] = ['tagFieldHandle', 'unique', 'targetClass' => FieldRecord::class, 'targetAttribute' => ['tagFieldHandle' => 'handle'], 'on' => 'convert-taxonomy'];
        return $rules;
    }
}
