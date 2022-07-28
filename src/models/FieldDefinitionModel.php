<?php

namespace vnali\migratefromwordpress\models;

use Craft;
use craft\base\Model;

use craft\helpers\StringHelper;
use craft\validators\HandleValidator;
use vnali\migratefromwordpress\helpers\FieldHelper;
use vnali\migratefromwordpress\helpers\GeneralHelper;
use vnali\migratefromwordpress\helpers\TableHelper;

class FieldDefinitionModel extends Model
{
    /**
     * @var int If field should be converted or not.
     */
    public $convert;

    /**
     * @var string What type of Craft field should WordPress field convert to.
     */
    public $convertTo;

    /**
     * @var string Craft field's handle
     */
    public $craftField;

    /**
     * @var string Craft field container -table, Matrix-
     */
    public $containerField;

    /**
     * @var string WordPress field handle.
     */
    public $handle;

    /**
     * @var string WordPress field label.
     */
    public $label;

    /**
     * @var string WordPress field type.
     */
    public $wordpressType;

    public function init(): void
    {
        parent::init();
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['convert'], 'in', 'range' => ['0', '1']];
        $rules[] = [['convertTo', 'craftField'], 'required', 'skipOnEmpty' => false, 'when' => function($model) {
            return $model->convert == '1';
        }, 'message' => '{attribute} (Field/Column Handle) is required for ' . $this->label . ' (' . $this->handle . ')'];
        $rules[] = [
            ['convertTo'],
            function($attribute, $params, $validator) {
                if ($this->convertTo == 'craft\redactor\Field') {
                    if (!Craft::$app->plugins->isPluginInstalled('redactor') || !Craft::$app->plugins->isPluginEnabled('redactor')) {
                        $this->addError($attribute, 'redactor plugin  is not installed or enabled');
                    }
                } elseif ($this->convertTo == 'craft\ckeditor\Field') {
                    if (!Craft::$app->plugins->isPluginInstalled('ckeditor') || !Craft::$app->plugins->isPluginEnabled('ckeditor')) {
                        $this->addError($attribute, 'ckeditor plugin  is not installed or enabled');
                    }
                }
            }, 'skipOnEmpty' => true,
        ];
        $rules[] = [
            ['convertTo'],
            function($attribute, $params, $validator) {
                if (!in_array($this->convertTo, array_keys(GeneralHelper::convertTo($this->wordpressType)))) {
                    $this->addError($attribute, 'Invalid Craft field/column type: ' . $this->convertTo . ' for ' . $this->label . ' (' . $this->handle . ')
                     with type: ' . $this->wordpressType);
                }
                // Some Craft field types couldn't be selected if container type is table
                $fieldContainers = explode('|', $this->containerField);
                foreach ($fieldContainers as $fieldContainer) {
                    $container = explode('-', $fieldContainer);
                    if (isset($container[1]) && $container[1] == 'Table' && ($this->convertTo == 'craft\fields\MultiSelect')) {
                        $this->addError($attribute, 'Invalid Craft field/column type: ' . $this->convertTo . ' for table container');
                    }
                    if (isset($container[1]) && $container[1] == 'SuperTable' &&
                        ((!Craft::$app->plugins->isPluginInstalled('super-table') || !Craft::$app->plugins->isPluginEnabled('super-table')))) {
                        $this->addError($attribute, 'Super Table plugin is not installed/enabled yet.');
                    }
                }
            }, 'skipOnEmpty' => true,
        ];
        $rules[] = [
            ['craftField'],
            function($attribute, $params, $validator) {
                $matchedFields = FieldHelper::findField(StringHelper::camelCase($this->craftField), null, $this->containerField);
                if (isset($matchedFields[0])) {
                    $item = null;
                    $convertTo = null;
                    if ($matchedFields[0]['type'] == 'field') {
                        $item = get_class($matchedFields[0]['field']);
                        $convertTo = $this->convertTo;
                    } elseif ($matchedFields[0]['type'] == 'column') {
                        $item = $matchedFields[0]['column']['type'];
                        $convertTo = TableHelper::fieldType2ColumnType($this->convertTo);
                    }
                    if ($convertTo != $item) {
                        $this->addError($attribute, $this->label . ' (' . $this->handle . ') field currently has type \'' . $item . '\'. requested type is: \'' . $convertTo . '\'');
                    }
                }
            }, 'skipOnEmpty' => true,
        ];
        $rules[] = [
            ['craftField'],
            HandleValidator::class,
            'reservedWords' => [
                'ancestors',
                'archived',
                'attributeLabel',
                'attributes',
                'behavior',
                'behaviors',
                'children',
                'contentTable',
                'dateCreated',
                'dateUpdated',
                'descendants',
                'enabled',
                'enabledForSite',
                'error',
                'errors',
                'errorSummary',
                'fieldValue',
                'fieldValues',
                'id',
                'language',
                'level',
                'localized',
                'lft',
                'link',
                'localized',
                'name', // global set-specific
                'next',
                'nextSibling',
                'owner',
                'parent',
                'parents',
                'postDate', // entry-specific
                'prev',
                'prevSibling',
                'ref',
                'rgt',
                'root',
                'scenario',
                'searchScore',
                'siblings',
                'site',
                'slug',
                'sortOrder',
                'status',
                'title',
                'uid',
                'uri',
                'url',
                'username', // user-specific
            ],
        ];
        return $rules;
    }
}
