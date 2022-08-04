<?php

namespace vnali\migratefromwordpress\helpers;

use Craft;

use craft\base\FieldInterface;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Matrix;
use craft\fields\Table;
use craft\helpers\ArrayHelper;
use craft\helpers\StringHelper;
use craft\models\FieldLayoutTab;
use craft\models\MatrixBlockType;

use verbb\supertable\fields\SuperTableField;
use verbb\supertable\models\SuperTableBlockTypeModel;
use verbb\supertable\SuperTable;
use vnali\migratefromwordpress\MigrateFromWordPress as MigrateFromWordPressPlugin;
use yii\caching\TagDependency;
use yii\web\ServerErrorHttpException;

class FieldHelper
{
    public static $_parentFieldTypes = [
        'link',
        'timestamp',
        'acf group',
        'acf link',
        'gutenberg',
    ];

    /**
     * Filter suggested field based on type, container and field layout.
     *
     * @param string|null $fieldHandle
     * @param string|null $fieldType
     * @param string $fieldContainer
     * @param string $limitFieldsToLayout
     * @param string $item
     * @param int $itemId
     * @return array
     */
    public static function findField(string $fieldHandle = null, string $fieldType = null, string $fieldContainer = '', string $limitFieldsToLayout = null, string $item = null, int $itemId = null): array
    {
        $fieldsArray = [];
        if ($limitFieldsToLayout == 'true') {
            switch ($item) {
                case 'page':
                case 'post':
                    if ($itemId) {
                        $entryType = Craft::$app->sections->getEntryTypeById($itemId);
                        $fieldLayout = $entryType->getFieldLayout();
                    } else {
                        throw new ServerErrorHttpException('itemId is not passed');
                    }
                    break;
                case 'user':
                    $user = User::find()->one();
                    $fieldLayout = $user->getFieldLayout();
                    break;
                default:
                    throw new ServerErrorHttpException($item . 'not defined');
            }
        }
        if (empty($fieldContainer)) {
            if ($fieldHandle) {
                if ($limitFieldsToLayout == 'true') {
                    if (!isset($fieldLayout)) {
                        throw new ServerErrorHttpException('field layout is not set');
                    }
                    $fieldItem = $fieldLayout->getFieldByHandle($fieldHandle);
                } else {
                    $fieldItem = Craft::$app->getFields()->getFieldByHandle($fieldHandle);
                }
                if ($fieldItem) {
                    if ($fieldType) {
                        if (get_class($fieldItem) == $fieldType) {
                            $fieldsArray[] = ['type' => 'field', 'field' => $fieldItem, 'value' => $fieldItem->handle, 'label' => $fieldItem->handle];
                        }
                    } else {
                        $fieldsArray[] = ['type' => 'field', 'field' => $fieldItem, 'value' => $fieldItem->handle, 'label' => $fieldItem->handle];
                    }
                }
            } else {
                if ($limitFieldsToLayout == 'true') {
                    if (!isset($fieldLayout)) {
                        throw new ServerErrorHttpException('field layout is not set');
                    }
                    $fieldItems = $fieldLayout->getCustomFields();
                } else {
                    $fieldItems = Craft::$app->fields->getAllFields();
                }
                foreach ($fieldItems as $key => $fieldItem) {
                    if ($fieldType) {
                        if (get_class($fieldItem) == $fieldType) {
                            $fieldsArray[] = ['type' => 'field', 'field' => $fieldItem, 'value' => $fieldItem->handle, 'label' => $fieldItem->handle];
                        }
                    } else {
                        $fieldsArray[] = ['type' => 'field', 'field' => $fieldItem, 'value' => $fieldItem->handle, 'label' => $fieldItem->handle];
                    }
                }
            }
        } else {
            /** @var string|null $container0Type */
            $container0Type = null;
            /** @var string|null $container0Handle */
            $container0Handle = null;
            /** @var string|null $container1Type */
            $container1Type = null;
            /** @var string|null $container1Handle */
            $container1Handle = null;
            /** @var string|null $container2Type */
            $container2Type = null;
            /** @var string|null $container2Handle */
            $container2Handle = null;
            $fieldContainers = explode('|', $fieldContainer);
            foreach ($fieldContainers as $key => $fieldContainer) {
                $containerHandleVar = 'container' . $key . 'Handle';
                $containerTypeVar = 'container' . $key . 'Type';
                $container = explode('-', $fieldContainer);
                if (isset($container[0])) {
                    $$containerHandleVar = $container[0];
                }
                if (isset($container[1])) {
                    $$containerTypeVar = $container[1];
                }
            }
            if (isset($container0Type) && $container0Type == 'Matrix' && isset($container1Type) && $container1Type == 'BlockType') {
                $matrixField = Craft::$app->fields->getFieldByHandle($container0Handle);
                if ($matrixField) {
                    $matrixBlockTypes = Craft::$app->matrix->getBlockTypesByFieldId($matrixField->id);
                    foreach ($matrixBlockTypes as $key => $matrixBlockType) {
                        if ($matrixBlockType->handle == $container1Handle) {
                            $blockTypeFields = $matrixBlockType->getCustomFields();
                            foreach ($blockTypeFields as $blockTypeField) {
                                if (!isset($container2Type)) {
                                    if ($fieldType) {
                                        if (get_class($blockTypeField) != $fieldType) {
                                            continue;
                                        }
                                    }
                                    if ($fieldHandle) {
                                        if ($blockTypeField->handle != $fieldHandle) {
                                            continue;
                                        }
                                    }
                                    $fieldsArray[] = ['type' => 'field', 'field' => $blockTypeField, 'value' => $blockTypeField->handle, 'label' => $blockTypeField->name];
                                } elseif ($container2Type == 'Table') {
                                    if (get_class($blockTypeField) == 'craft\fields\Table' && $blockTypeField->handle == $container2Handle) {
                                        foreach ($blockTypeField->columns as $key => $tableColumn) {
                                            if ($fieldType) {
                                                if ($tableColumn['type'] != TableHelper::fieldType2ColumnType($fieldType)) {
                                                    continue;
                                                }
                                            }
                                            if ($fieldHandle) {
                                                if ($tableColumn['handle'] != $fieldHandle) {
                                                    continue;
                                                }
                                            }
                                            if (!empty($tableColumn['handle'])) {
                                                $fieldsArray[] = ['type' => 'column', 'column' => $tableColumn, 'value' => $tableColumn['handle'], 'label' => $tableColumn['handle'], 'table' => $blockTypeField, 'tableKey' => $key];
                                            }
                                        }
                                        break;
                                    }
                                }
                            }
                            break;
                        }
                    }
                }
            } elseif (isset($container0Type) && $container0Type == 'Table') {
                $fields = Craft::$app->fields->getAllFields();
                foreach ($fields as $field) {
                    if (get_class($field) == 'craft\fields\Table' && $container0Handle == $field->handle) {
                        foreach ($field->columns as $key => $tableColumn) {
                            if ($fieldType) {
                                if ($tableColumn['type'] != TableHelper::fieldType2ColumnType($fieldType)) {
                                    continue;
                                }
                            }
                            if ($fieldHandle) {
                                if ($tableColumn['handle'] != $fieldHandle) {
                                    continue;
                                }
                            }
                            if (!empty($tableColumn['handle'])) {
                                $fieldsArray[] = ['type' => 'column', 'column' => $tableColumn, 'value' => $tableColumn['handle'], 'label' => $tableColumn['handle'], 'table' => $field, 'tableKey' => $key];
                            }
                        }
                        break;
                    }
                }
            } elseif (isset($container0Type) && $container0Type == 'SuperTable') {
                $superTableField = Craft::$app->fields->getFieldByHandle($container0Handle);
                if ($superTableField) {
                    $blocks = SuperTable::$plugin->getService()->getBlockTypesByFieldId($superTableField->id);
                    if (isset($blocks[0])) {
                        $fieldLayout = $blocks[0]->getFieldLayout();
                        $superTableFields = $fieldLayout->getCustomFields();
                        foreach ($superTableFields as $key => $fieldItem) {
                            if (isset($container1Type) && $container1Type == 'Table') {
                                if (get_class($fieldItem) == 'craft\fields\Table' && $fieldItem->handle == $container1Handle) {
                                    foreach ($fieldItem->columns as $key => $field) {
                                        if ($fieldType) {
                                            if ($field['type'] != TableHelper::fieldType2ColumnType($fieldType)) {
                                                continue;
                                            }
                                        }
                                        if ($fieldHandle) {
                                            if ($field['handle'] != $fieldHandle) {
                                                continue;
                                            }
                                        }
                                        if (!empty($field['handle'])) {
                                            $fieldsArray[] = ['type' => 'column', 'table' => $fieldItem, 'column' => $field, 'value' => $field['handle'], 'label' => $field['handle']];
                                        }
                                    }
                                    break;
                                }
                            } else {
                                if ($fieldHandle) {
                                    if ($fieldItem->handle == $fieldHandle) {
                                        if ($fieldType) {
                                            if (get_class($fieldItem) == $fieldType) {
                                                $fieldsArray[] = ['type' => 'field', 'field' => $fieldItem, 'value' => $fieldItem->handle, 'label' => $fieldItem->name];
                                            }
                                        } else {
                                            $fieldsArray[] = ['type' => 'field', 'field' => $fieldItem, 'value' => $fieldItem->handle, 'label' => $fieldItem->name];
                                        }
                                    }
                                } else {
                                    if ($fieldType) {
                                        if (get_class($fieldItem) == $fieldType) {
                                            $fieldsArray[] = ['type' => 'field', 'field' => $fieldItem, 'value' => $fieldItem->handle, 'label' => $fieldItem->name];
                                        }
                                    } else {
                                        $fieldsArray[] = ['type' => 'field', 'field' => $fieldItem, 'value' => $fieldItem->handle, 'label' => $fieldItem->name];
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return $fieldsArray;
    }

    /**
     * Create requested field.
     *
     * @param string $key
     * @param array $fieldSettings
     * @param array $fieldMappings
     * @param FieldInterface $fieldItem
     * @param array $fieldMappingsExtra
     * @param string $itemType
     * @param array $fieldDefinitions
     * @param string|null $itemId
     */
    public static function createFields(
        string $key,
        array $fieldSettings,
        array &$fieldMappings,
        FieldInterface &$fieldItem = null,
        array &$fieldMappingsExtra,
        string $itemType = null,
        array $fieldDefinitions,
        ?string $itemId = '',
    ) {
        switch ($itemType) {
            case 'file':
            case 'user':
                $cacheKey = 'migrate-from-wordpress-' . $itemType . '-fields';
                break;
            case 'page':
            case 'post':
            case 'taxonomy':
            case 'menu':
            case 'navigation':
                $cacheKey = 'migrate-from-wordpress-' . $itemType . '-' . $itemId . '-fields';
                break;
            default:
                throw new ServerErrorHttpException("not defined item type " . $itemType);
        }
        $fieldDefinitions = Craft::$app->cache->get($cacheKey);
        $fieldDefinitions = json_decode($fieldDefinitions, true);

        $fieldMap = $fieldMappings;

        if (isset($fieldDefinitions[$key]['config']['label'])) {
            $label = $fieldDefinitions[$key]['config']['label'];
        } else {
            throw new ServerErrorHttpException('label is not available for ' . $key);
        }

        $finalFieldHandle = null;
        $handle = $fieldDefinitions[$key]['wordpressHandle'];

        if (isset($fieldDefinitions[$key]['config']['parent'])) {
            $parent = $fieldDefinitions[$key]['config']['parent'];
            $feedValue = "$parent/value/$handle";
        } else {
            $feedValue = "$handle/value";
        }

        $type = $fieldSettings['type'];
        $convertTo = $fieldSettings['convertTo'];
        $fieldHandle = $fieldSettings['craftField'];
        $craftField = explode('--', $fieldHandle);
        $fieldHandle = $craftField[0];
        $fieldHandle = StringHelper::camelCase($fieldHandle);
        if (isset($craftField[1]) && isset($craftField[2]) && $craftField[2] == 'asset') {
            $volumeId = (int) $craftField[1];
        } elseif (isset($craftField[1]) && isset($craftField[2]) && $craftField[2] == 'tag') {
            $tagGroupId = (int) $craftField[1];
        } elseif (isset($craftField[1]) && isset($craftField[2]) && $craftField[2] == 'category') {
            $categoryGroupId = (int) $craftField[1];
        }

        $translationMethod = 'site';
        $instructions = null;
        if (isset($fieldSettings['containerField'])) {
            $fieldContainer = $fieldSettings['containerField'];
        } else {
            $fieldContainer = '';
        }

        $matchedFields = FieldHelper::findField($fieldHandle, $convertTo, $fieldContainer);
        if (!empty($fieldContainer)) {
            $groupId = null;
        } else {
            $groupId = 1;
        }

        if (empty($matchedFields)) {
            switch ($convertTo) {
                case 'craft\fields\Color':
                    $field = new \craft\fields\Color([
                        "groupId" => $groupId,
                        "name" => $label,
                        "handle" => $fieldHandle,
                        "translationMethod" => $translationMethod,
                    ]);
                    break;
                case 'craft\fields\Date':
                    $showDate = true;
                    $showTime = false;
                    $field = new \craft\fields\Date([
                        "groupId" => $groupId,
                        "name" => $label,
                        "handle" => $fieldHandle,
                        "translationMethod" => $translationMethod,
                        "minuteIncrement" => 30,
                        "showDate" => $showDate,
                        "showTime" => $showTime,
                    ]);
                    break;
                case 'craft\fields\Time':
                    $field = new \craft\fields\Time([
                        "groupId" => $groupId,
                        "name" => $label,
                        "handle" => $fieldHandle,
                        "translationMethod" => $translationMethod,
                        "minuteIncrement" => 30,
                    ]);
                    break;
                case 'craft\fields\Url':
                    $field = new \craft\fields\Url([
                        "groupId" => $groupId,
                        "name" => $label,
                        "handle" => $fieldHandle,
                        "translationMethod" => $translationMethod,
                        "instructions" => $instructions,
                    ]);
                    break;
                case 'craft\fields\Email':
                    $field = new \craft\fields\Email([
                        "groupId" => $groupId,
                        "name" => $label,
                        "handle" => $fieldHandle,
                        "translationMethod" => $translationMethod,
                        "instructions" => $instructions,
                    ]);
                    break;
                case 'craft\fields\Users':
                    $field = new \craft\fields\Users([
                        "groupId" => $groupId,
                        "name" => $label,
                        "handle" => $fieldHandle,
                        "translationMethod" => $translationMethod,
                    ]);
                    break;
                case 'craft\fields\Entries':
                    $field = new \craft\fields\Entries([
                        "groupId" => $groupId,
                        "name" => $label,
                        "handle" => $fieldHandle,
                        "translationMethod" => $translationMethod,
                    ]);
                    break;
                case 'craft\fields\PlainText':
                    $multiline = "";
                    if ($type == "text_with_summary" || $type == "text_long") {
                        $multiline = 1;
                    }
                    $field = new \craft\fields\PlainText([
                        "groupId" => $groupId,
                        "name" => $label,
                        "handle" => $fieldHandle,
                        "translationMethod" => $translationMethod,
                        "multiline" => $multiline,
                        "instructions" => $instructions,
                    ]);
                    break;
                case 'craft\redactor\Field':
                    $field = new \craft\redactor\Field([
                        "groupId" => $groupId,
                        "name" => $label,
                        "handle" => $fieldHandle,
                        "translationMethod" => $translationMethod,
                    ]);
                    break;
                case 'craft\ckeditor\Field':
                    $field = new \craft\ckeditor\Field([
                        "groupId" => $groupId,
                        "name" => $label,
                        "handle" => $fieldHandle,
                        "translationMethod" => $translationMethod,
                    ]);
                    break;
                case 'craft\fields\Number':
                    $min = null;
                    $max = null;
                    $prefix = "";
                    $suffix = "";
                    $scale = 0;
                    $field = new \craft\fields\Number([
                        "groupId" => $groupId,
                        "name" => $label,
                        "handle" => $fieldHandle,
                        "decimals" => $scale,
                        "defaultValue" => 1,
                        "max" => $max,
                        "min" => $min,
                        "prefix" => $prefix,
                        "size" => null,
                        "suffix" => $suffix,
                        "translationMethod" => $translationMethod,
                    ]);
                    break;
                case 'craft\fields\Checkboxes':
                    $options = [];
                    $field = new \craft\fields\Checkboxes([
                        "groupId" => $groupId,
                        "name" => $label,
                        "handle" => $fieldHandle,
                        "options" => $options,
                    ]);
                    break;
                case 'craft\fields\Dropdown':
                    $options = [];
                    $field = new \craft\fields\Dropdown([
                        "groupId" => $groupId,
                        "name" => $label,
                        "handle" => $fieldHandle,
                        "options" => $options,
                    ]);
                    break;
                case 'craft\fields\MultiSelect':
                    $options = [];
                    $field = new \craft\fields\MultiSelect([
                        "groupId" => $groupId,
                        "name" => $label,
                        "handle" => $fieldHandle,
                        "options" => $options,
                    ]);
                    break;
                case 'craft\fields\RadioButtons':
                    $options = [];
                    $field = new \craft\fields\RadioButtons([
                        "groupId" => $groupId,
                        "name" => $label,
                        "handle" => $fieldHandle,
                        "options" => $options,
                    ]);
                    break;
                case 'craft\fields\Lightswitch':
                    $field = new \craft\fields\Lightswitch([
                        "groupId" => $groupId,
                        "name" => $label,
                        "handle" => $fieldHandle,
                        "instructions" => $instructions,
                    ]);
                    break;
                case 'craft\fields\Tags':
                    $field = Craft::$app->fields->getFieldByHandle($fieldHandle);
                    if (!$field) {
                        if (!isset($tagGroupId)) {
                            throw new ServerErrorHttpException('tag group id is not defined');
                        }
                        $tagGroup = Craft::$app->tags->getTagGroupById($tagGroupId);
                        $field = new \craft\fields\Tags([
                            "groupId" => 1,
                            "name" => $fieldHandle,
                            "handle" => StringHelper::camelCase($fieldHandle),
                            "allowMultipleSources" => false,
                            "allowLimit" => false,
                            "sources" => "*",
                            "source" => "taggroup:" . $tagGroup->uid,
                        ]);
                    }
                    break;
                case 'craft\fields\Categories':
                    $field = Craft::$app->fields->getFieldByHandle($fieldHandle);
                    if (!$field) {
                        if (!isset($categoryGroupId)) {
                            throw new ServerErrorHttpException('category group id is not defined');
                        }
                        $categoryGroup = Craft::$app->categories->getGroupById($categoryGroupId);
                        $field = new \craft\fields\Categories([
                            "groupId" => 1,
                            "name" => $fieldHandle,
                            "handle" => StringHelper::camelCase($fieldHandle),
                            "allowMultipleSources" => false,
                            "allowLimit" => false,
                            "sources" => "*",
                            "source" => "group:" . $categoryGroup->uid,
                        ]);
                    }
                    break;
                case 'craft\fields\Assets':
                    if (!isset($volumeId)) {
                        throw new ServerErrorHttpException('volume id is not defined');
                    }
                    $volumeRecord = Craft::$app->volumes->getVolumeById($volumeId);
                    $field = new \craft\fields\Assets([
                        "groupId" => $groupId,
                        "name" => $fieldHandle,
                        "handle" => $fieldHandle,
                        'defaultUploadLocationSource' => 'volume:' . $volumeRecord->uid,
                        "defaultUploadLocationSubpath" => "",
                        'singleUploadLocationSource' => 'volume:' . $volumeRecord->uid,
                        "singleUploadLocationSubpath" => "",
                        "restrictFiles" => false,
                    ]);
                    break;
                default:
                    throw new ServerErrorHttpException("undefined type $convertTo for field handle " . $fieldHandle);
            }
        }

        if ($fieldContainer == '') {
            if (empty($matchedFields)) {
                if (!isset($field)) {
                    throw new ServerErrorHttpException('field is not defined');
                }
                if (!Craft::$app->getFields()->saveField($field)) {
                    Craft::error('Field cannot be saved:' . json_encode($field->errors));
                    throw new ServerErrorHttpException('field cannot be saved:' . $field->handle . ' error: ' . json_encode($field->getErrors()));
                }
                $finalFieldHandle = $field->handle;
            }
            $fieldItem = Craft::$app->getFields()->getFieldByHandle($fieldHandle);

            $fieldMap[$fieldHandle]['field'] = $convertTo;
            $fieldMap[$fieldHandle]['default'] = '';
            $fieldMap[$fieldHandle]['node'] = $handle . '/value';
            if ($convertTo == 'craft\fields\Assets') {
                if ($itemType == 'media') {
                    $fieldMap[$fieldHandle]['options']['upload'] = "1";
                } else {
                    $fieldMap[$fieldHandle]['options']['upload'] = "0";
                    $fieldMap[$fieldHandle]['options']['conflict'] = "index";
                }
            }

            if ($convertTo == 'craft\fields\Categories' || $convertTo == 'craft\fields\Tags') {
                $fieldMap[$fieldHandle]['options']['create'] = "0";
                // Feed-me ui check for title, we force to wordpressUUID
                // Get exact name of column uuid
                $uuidField = Craft::$app->fields->getFieldByHandle('wordpressUUID');
                $uuidField = 'field_wordpressUUID_' . $uuidField->columnSuffix;
                //
                $fieldMap[$fieldHandle]['options']['match'] = $uuidField;
            } elseif ($convertTo == 'craft\fields\Entries') {
                // Get exact name of column uuid
                $uuidField = Craft::$app->fields->getFieldByHandle('wordpressUUID');
                $uuidField = 'field_wordpressUUID_' . $uuidField->columnSuffix;
                //
                $fieldMap[$fieldHandle]['options']['match'] = $uuidField;
            } elseif ($convertTo == 'craft\fields\Checkboxes' || $convertTo == 'craft\fields\Dropdown') {
                $fieldMap[$fieldHandle]['options']['match'] = 'value';
            } elseif ($type == 'block_field' && $convertTo == 'craft\fields\Entries') {
                // Get exact name of column uuid
                $uuidField = Craft::$app->fields->getFieldByHandle('wordpressUUID');
                $uuidField = 'field_wordpressUUID_' . $uuidField->columnSuffix;
                $fieldMap[$fieldHandle]['options']['match'] = $uuidField;
            } elseif ($convertTo == 'craft\fields\Users') {
                // Get exact name of column uuid
                $uuidField = Craft::$app->fields->getFieldByHandle('wordpressUserId');
                $uuidField = 'field_wordpressUserId_' . $uuidField->columnSuffix;
                $fieldMap[$fieldHandle]['options']['match'] = $uuidField;
            } elseif ($convertTo == 'craft\fields\Date') {
                // Set match to auto for acf date and acf date time convert to craft date
                $fieldMap[$fieldHandle]['options']['match'] = 'auto';
            }
        } else {
            $fieldContainers = explode('|', $fieldContainer);
            $container1 = explode('-', $fieldContainers[0]);
            if (isset($fieldContainers[1])) {
                $container2 = explode('-', $fieldContainers[1]);
            }
            if (isset($fieldContainers[2])) {
                $container3 = explode('-', $fieldContainers[2]);
            }
            if ($container1[1] == 'Matrix') {
                $tableHandle = null;
                $tableFinded = false;
                $fieldFinded = false;
                $columnFinded = false;
                $matrixHandle = StringHelper::camelCase($container1[0]);
                $blockTypeFields = [];
                if (!isset($container2[0])) {
                    throw new ServerErrorHttpException('Block type is not set');
                }
                $blockTypeHandle = StringHelper::camelCase($container2[0]);
                $containerStr = $matrixHandle . '-' . 'Matrix|' . $blockTypeHandle . '-BlockType';
                if (isset($container3[0]) && isset($container3[1]) && $container3[1] == 'Table') {
                    $tableHandle = $container3[0];
                    $tableFinded = false;
                    $tableHandle = StringHelper::camelCase($matrixHandle . '_' . $tableHandle);
                    $containerStr = $matrixHandle . '-' . 'Matrix|' . $blockTypeHandle . '-BlockType' . '|' . $tableHandle . '-Table';
                }
                $fieldDefinitions[$key]['containerTarget'] = $containerStr;
                $matrix = Craft::$app->fields->getFieldByHandle($matrixHandle);
                $blockTypeFinded = false;
                $targetBlockTypeId = null;
                if ($matrix) {
                    $blockTypes = Craft::$app->matrix->getBlockTypesByFieldId($matrix->id);
                    foreach ($blockTypes as $blockType) {
                        if ($blockType->handle == $blockTypeHandle) {
                            $blockTypeFinded = true;
                            $blockTypeFields = $blockType->getCustomFields();
                            $targetBlockTypeId = $blockType->id;
                            foreach ($blockTypeFields as $blockTypeField) {
                                if ($tableHandle) {
                                    if ($blockTypeField->handle == $tableHandle) {
                                        $table = $blockTypeField;
                                        $tableFinded = true;
                                        /** @var Table $blockTypeField */
                                        $tableColumns = $blockTypeField->columns;
                                        foreach ($tableColumns as $key => $tableColumn) {
                                            if ($tableColumn['handle'] == $fieldHandle) {
                                                $col = $key;
                                                $columnFinded = true;
                                                break;
                                            }
                                        }
                                        break;
                                    }
                                } else {
                                    if ($blockTypeField->handle == $fieldHandle) {
                                        $fieldFinded = true;
                                        break;
                                    }
                                }
                            }
                            break;
                        }
                    }
                } else {
                    $matrix = new Matrix();
                    $matrix->handle = $matrixHandle;
                    $matrix->name = $matrixHandle;
                    $matrix->groupId = 1;
                    $matrix->propagationMethod = Matrix::PROPAGATION_METHOD_ALL;
                    $blockTypes = [];
                }
                if (!$blockTypeFinded) {
                    $blockType = new MatrixBlockType();
                    $blockType->handle = $blockTypeHandle;
                    $blockType->name = $blockTypeHandle;
                    $blockTypeFields = [];
                    $fieldLayout = $blockType->getFieldLayout();
                    if (($fieldLayoutTab = $fieldLayout->getTabs()[0] ?? null) === null) {
                        $fieldLayoutTab = new FieldLayoutTab();
                        $fieldLayoutTab->name = 'Content';
                        $fieldLayoutTab->sortOrder = 1;
                        $fieldLayout->setTabs([$fieldLayoutTab]);
                    }
                }
                if (!$blockTypeFinded || !$tableFinded) {
                    if ($tableHandle) {
                        $table = new Table();
                        $table->handle = $tableHandle;
                        $table->name = $tableHandle;
                        $table->translationMethod = 'site';
                        $blockTypeFields[] = $table;
                        $col = "col1";
                        $field = $table;
                    }
                }
                if ($tableFinded && !$columnFinded && isset($table)) {
                    /** @var Table $table */
                    $tableColumns = $table->columns;
                    $maxCol = 0;
                    foreach ($tableColumns as $key => $tableColumn) {
                        $col = explode('col', $key);
                        if ((int) $col[1] > $maxCol) {
                            $maxCol = (int) $col[1];
                        }
                    }
                    $col = "col" . ($maxCol + 1);
                }
                if (!$tableHandle && $fieldFinded == false) {
                    if (!isset($field)) {
                        throw new ServerErrorHttpException('field is not defined');
                    }
                    $blockTypeFields[] = $field;
                } elseif ($tableHandle && $columnFinded == false && isset($table) && isset($col)) {
                    /** @var Table $table */
                    $table->columns[$col]['heading'] = $fieldHandle;
                    $table->columns[$col]['handle'] = $fieldHandle;
                    $table->columns[$col]['width'] = "";
                    $table->columns[$col]['type'] = TableHelper::fieldType2ColumnType($convertTo);
                }

                if ($tableHandle) {
                    if (isset($table) && isset($col)) {
                        /** @var Table $table */
                        $finalFieldHandle = $table->columns[$col]['handle'];
                    } else {
                        throw new ServerErrorHttpException('table and col var is not defined');
                    }
                } else {
                    $finalFieldHandle = $fieldHandle;
                }

                $blockTypesArray = [];

                // Don't change other block types
                foreach ($blockTypes as $blockType) {
                    if ($blockType->handle != $blockTypeHandle) {
                        $blockTypesArray[$blockType->id] = $blockType;
                    }
                }

                // Apply Changes to target block type
                $targetBlockType = [];
                $targetBlockType['handle'] = $blockTypeHandle;
                $targetBlockType['name'] = $blockTypeHandle;

                /** @var FieldInterface $blockTypeField */
                foreach ($blockTypeFields as $blockTypeField) {
                    $config = Craft::$app->fields->createFieldConfig($blockTypeField);
                    //$config['typesettings'] = $blockTypeField->settings;
                    $config['typesettings'] = $blockTypeField->getSettings();
                    unset($config['settings']);
                    $targetBlockType['fields'][$blockTypeField->id] = $config;
                }

                if (!isset($field)) {
                    throw new ServerErrorHttpException('field is not defined');
                }
                $targetBlockType['fields']['new1'] = $field;

                if (!$targetBlockTypeId) {
                    $targetBlockTypeId = 'new1';
                }
                $blockTypesArray[$targetBlockTypeId] = $targetBlockType;
                /** @var Matrix $matrix */
                $matrix->setBlockTypes($blockTypesArray);

                if (!Craft::$app->getFields()->saveField($matrix)) {
                    $error = json_encode($matrix->getErrors());
                    Craft::warning("$error");
                    return false;
                }
                $matrix = Craft::$app->getFields()->getFieldByHandle($matrixHandle);
                // TODO: check if matrix is not currently in array
                $fieldItem = $matrix;
                // Set field mapping for matrix

                if ($tableHandle && isset($col)) {
                    $fieldMap[$matrixHandle]['field'] = 'craft\fields\Matrix';

                    $fieldMap[$matrixHandle]['blocks'][$blockTypeHandle]['fields'][$tableHandle]['field'] = 'craft\fields\Table';
                    $fieldMap[$matrixHandle]['blocks'][$blockTypeHandle]['fields'][$tableHandle]['fields'][$col]['handle'] = $fieldHandle;
                    $fieldMap[$matrixHandle]['blocks'][$blockTypeHandle]['fields'][$tableHandle]['fields'][$col]['type'] = TableHelper::fieldType2ColumnType($convertTo);
                    $fieldMap[$matrixHandle]['blocks'][$blockTypeHandle]['fields'][$tableHandle]['fields'][$col]['node'] = $handle . '/value';
                } elseif (isset($blockType)) {
                    $fieldMap[$matrixHandle]['field'] = 'craft\fields\Matrix';
                    $fieldMap[$matrixHandle]['blocks'][$blockTypeHandle]['fields'][$fieldHandle]['field'] = $convertTo;
                    $fieldMap[$matrixHandle]['blocks'][$blockTypeHandle]['fields'][$fieldHandle]['node'] = $feedValue;
                    $fieldMap[$matrixHandle]['blocks'][$blockTypeHandle]['fields'][$fieldHandle]['default'] = '';
                    if ($convertTo == 'craft\fields\Assets') {
                        $fieldMap[$matrixHandle]['blocks'][$blockTypeHandle]['fields'][$fieldHandle]['options']['upload'] = "0";
                        $fieldMap[$matrixHandle]['blocks'][$blockTypeHandle]['fields'][$fieldHandle]['options']['conflict'] = "index";
                    }
                    if ($convertTo == 'craft\fields\Categories' || $convertTo == 'craft\fields\Tags' || $convertTo == 'craft\fields\Entries') {
                        // Get exact name of column uuid
                        $uuidField = Craft::$app->fields->getFieldByHandle('wordpressUUID');
                        $uuidField = 'field_wordpressUUID_' . $uuidField->columnSuffix;
                        //
                        $fieldMap[$matrixHandle]['blocks'][$blockTypeHandle]['fields'][$fieldHandle]['options']['create'] = "0";
                        $fieldMap[$matrixHandle]['blocks'][$blockTypeHandle]['fields'][$fieldHandle]['options']['match'] = $uuidField;
                    }
                    if ($type == 'block_field' && $convertTo == 'craft\fields\Entries') {
                        $fieldMap[$matrixHandle]['blocks'][$blockTypeHandle]['fields'][$fieldHandle]['options']['match'] = "title";
                    }
                }
            } elseif ($container1[1] == 'Table') {
                $columnFinded = false;
                $tableHandle = $container1[0];
                $table = Craft::$app->fields->getFieldByHandle($tableHandle);
                /** @var Table|null $table */
                if ($table) {
                    $tableColumns = $table->columns;
                    $maxCol = 0;
                    foreach ($tableColumns as $key => $tableColumn) {
                        $column = explode('col', $key);
                        if ($tableColumn['heading'] != $fieldHandle) {
                            if ((int) $column[1] > $maxCol) {
                                $maxCol = (int) $column[1];
                            }
                        } else {
                            $columnFinded = true;
                            break;
                        }
                    }
                    if (!$columnFinded) {
                        $col = "col" . ($maxCol + 1);
                    } else {
                        $col = $key;
                    }
                } else {
                    $table = new Table();
                    $table->handle = $tableHandle;
                    $table->name = $tableHandle;
                    $table->groupId = 1;
                    $table->translationMethod = 'site';
                    $table->defaults = null;
                    $col = "col1";
                }
                if (!$columnFinded) {
                    $table->columns[$col]['heading'] = $fieldHandle;
                    $table->columns[$col]['handle'] = $fieldHandle;
                    $table->columns[$col]['width'] = "";
                    $tableColumnType = TableHelper::fieldType2ColumnType($convertTo);
                    if (!$tableColumnType) {
                        throw new ServerErrorHttpException('not supported for table' . $convertTo);
                    }
                    if ($tableColumnType == 'singleline' && ($type == "text_with_summary" || $type == "text_long")) {
                        $tableColumnType = 'multiline';
                    }
                    $table->columns[$col]['type'] = $tableColumnType;
                    if ($convertTo == 'craft\fields\Dropdown') {
                        if (isset($options)) {
                            $table->columns[$col]['options'] = $options;
                        }
                    }
                }
                //
                if (!Craft::$app->getFields()->saveField($table)) {
                    Craft::error('table couldn\'t save.' . '-' . json_encode($table->getErrors()), __METHOD__);
                    throw new ServerErrorHttpException('table couldn\'t save.' . '-' . json_encode($table->getErrors()));
                }

                // Table handle can be changed to different cases
                $tableHandle = $table->handle;
                // We save container of field to find it later
                $fieldDefinitions[$key]['containerTarget'] = $tableHandle . '-Table';
                $finalFieldHandle = $table->columns[$col]['handle'];

                $table = Craft::$app->getFields()->getFieldByHandle($tableHandle);
                $fieldItem = $table;

                // Get table column type
                $tableColumnType = TableHelper::fieldType2ColumnType($convertTo);

                $fieldMap[$tableHandle]['field'] = 'craft\fields\Table';
                $fieldMap[$tableHandle]['fields'][$col]['handle'] = $fieldHandle;
                $fieldMap[$tableHandle]['fields'][$col]['type'] = $tableColumnType;
                $fieldMap[$tableHandle]['fields'][$col]['node'] = $handle . '/value';
            } elseif ($container1[1] == 'SuperTable') {
                $superTableHandle = $container1[0];
                $tableHandle = null;
                $targetBlockTypeId = null;
                $superTableFields = [];

                if (isset($container2[0]) && $container2[0] != '') {
                    $tableHandle = $container2[0];
                    //$tableHandle = $superTableHandle . '_' . $tableHandle;
                }

                $containerStr = $superTableHandle . '-Matrix';
                if (isset($container2[0]) && isset($container2[1]) && $container2[1] == 'Table') {
                    $tableHandle = $container2[0];
                    $tableFinded = false;
                   // $tableHandle = StringHelper::camelCase($superTableHandle . '_' . $tableHandle);
                    $containerStr = $superTableHandle . '-Matrix|' . $tableHandle . '-Table';
                }
                $fieldDefinitions[$key]['containerTarget'] = $containerStr;

                $superTable = Craft::$app->fields->getFieldByHandle($superTableHandle);
                $tableFinded = false;

                if (!$superTable) {
                    // Create super table
                    $superTable = new SuperTableField([
                        'name' => $superTableHandle,
                        'handle' => $superTableHandle,
                        'groupId' => 1,
                        'fieldLayout' => 'row',
                    ]);
                    if (!Craft::$app->getFields()->saveField($superTable)) {
                        throw new ServerErrorHttpException('super table couldn\'t save.' . '-' . json_encode($superTable->getErrors()));
                    }
                } else {
                    $blocks = SuperTable::$plugin->getService()->getBlockTypesByFieldId($superTable->id);
                    if (isset($blocks[0])) {
                        $superModel = $blocks[0];
                        $superTableFields = $superModel->getCustomFields();
                        $targetBlockTypeId = $superModel->id;
                    }
                    if ($tableHandle) {
                        if (isset($blocks[0])) {
                            foreach ($superTableFields as $key => $superTableField) {
                                if ($superTableField->handle == $tableHandle) {
                                    $table = $superTableField;
                                    $tableFinded = true;
                                    break;
                                }
                            }
                        }
                    }
                }

                if ($tableHandle) {
                    $col = null;
                    if (!$tableFinded) {
                        $table = new Table();
                        $table->handle = $tableHandle;
                        $table->name = $tableHandle;
                        $col = "col1";
                        $table->columns[$col]['heading'] = $fieldHandle;
                        $table->columns[$col]['handle'] = $fieldHandle;
                        $table->columns[$col]['width'] = "";
                        $table->columns[$col]['type'] = TableHelper::fieldType2ColumnType($convertTo);
                        /*
                        if (!Craft::$app->getFields()->saveField($table)) {
                            Craft::error('Can not save table: ' . json_encode($table->getErrors()), __METHOD__);
                            throw new ServerErrorHttpException('Can not save table: ' . json_encode($table->getErrors()));
                        }
                        $field = Craft::$app->fields->getFieldByHandle($tableHandle);
                        */
                        $field = $table;
                    } elseif (isset($table)) {
                        $tableColumns = $table->columns;
                        $columnFinded = false;
                        $maxCol = 0;
                        foreach ($tableColumns as $key => $tableColumn) {
                            $col = explode('col', $key);
                            if ($tableColumn['heading'] != $fieldHandle) {
                                if ((int) $col[1] > $maxCol) {
                                    $maxCol = (int) $col[1];
                                }
                            } else {
                                $columnFinded = true;
                                break;
                            }
                        }
                        if (!$columnFinded) {
                            $col = "col" . ($maxCol + 1);
                        } else {
                            $col = $key;
                        }
                        if (!$columnFinded) {
                            $table->columns[$col]['heading'] = $fieldHandle;
                            $table->columns[$col]['handle'] = $fieldHandle;
                            $table->columns[$col]['width'] = "";
                            $table->columns[$col]['type'] = TableHelper::fieldType2ColumnType($convertTo);
                            if ($convertTo == 'craft\fields\Dropdown') {
                                if (isset($options)) {
                                    $table->columns[$col]['options'] = $options;
                                }
                            }
                        }
                    }

                    foreach ($superTableFields as $superTableField) {
                        $config = Craft::$app->fields->createFieldConfig($superTableField);
                        $config['typesettings'] = $superTableField->getSettings();
                        unset($config['settings']);
                        $targetBlockType['fields'][$superTableField->id] = $config;
                    }
                    
                    if (!isset($field)) {
                        throw new ServerErrorHttpException('field is not defined');
                    }

                    // Create field config
                    $config = Craft::$app->fields->createFieldConfig($field);
                    $config['typesettings'] = $field->getSettings();
                    unset($config['settings']);

                    $targetBlockType['fields']['new1'] = $config;

                    if (!$targetBlockTypeId) {
                        $targetBlockTypeId = 'new';
                    }
                    $blockTypesArray[$targetBlockTypeId] = $targetBlockType;

                    $superTable->setBlockTypes($blockTypesArray);
                    if (!Craft::$app->getFields()->saveField($superTable)) {
                        $error = json_encode($superTable->getErrors());
                        Craft::warning("$error");
                        return false;
                    }

                    $finalFieldHandle = $table->columns[$col]['handle'];

                    $fieldMap[$superTableHandle]['field'] = 'verbb\supertable\fields\SuperTableField';
                    $fieldMap[$superTableHandle]['blockTypeId'] = 1;

                    $fieldMap[$superTableHandle]['fields'][$tableHandle]['field'] = 'craft\fields\Table';
                    $fieldMap[$superTableHandle]['fields'][$tableHandle]['fields'][$col]['handle'] = $fieldHandle;
                    $fieldMap[$superTableHandle]['fields'][$tableHandle]['fields'][$col]['type'] = TableHelper::fieldType2ColumnType($convertTo);
                    $fieldMap[$superTableHandle]['fields'][$tableHandle]['fields'][$col]['node'] = $handle . '/value';
                } else {
                    if (empty($matchedFields)) {
                        if (!isset($field)) {
                            throw new ServerErrorHttpException('field is not defined');
                        }

                        foreach ($superTableFields as $superTableField) {
                            $config = Craft::$app->fields->createFieldConfig($superTableField);
                            $config['typesettings'] = $superTableField->getSettings();
                            unset($config['settings']);
                            $targetBlockType['fields'][$superTableField->id] = $config;
                        }

                        // Create field config
                        $config = Craft::$app->fields->createFieldConfig($field);
                        $config['typesettings'] = $field->getSettings();
                        unset($config['settings']);

                        $targetBlockType['fields']['new1'] = $config;

                        if (!$targetBlockTypeId) {
                            $targetBlockTypeId = 'new';
                        }
                        $blockTypesArray[$targetBlockTypeId] = $targetBlockType;

                        $superTable->setBlockTypes($blockTypesArray);
                        if (!Craft::$app->getFields()->saveField($superTable)) {
                            $error = json_encode($superTable->getErrors());
                            Craft::warning("$error");
                            return false;
                        }

                        $finalFieldHandle = $fieldHandle;
                    }

                    $fieldMap[$superTableHandle]['field'] = 'verbb\supertable\fields\SuperTableField';
                    $fieldMap[$superTableHandle]['blockTypeId'] = 1;

                    $fieldMap[$superTableHandle]['fields'][$fieldHandle]['field'] = $convertTo;
                    $fieldMap[$superTableHandle]['fields'][$fieldHandle]['default'] = '';
                    $fieldMap[$superTableHandle]['fields'][$fieldHandle]['node'] = $feedValue;

                    if ($convertTo == 'craft\fields\Categories' || $convertTo == 'craft\fields\Tags' || $convertTo == 'craft\fields\Entries') {
                        // Get exact name of column uuid
                        $uuidField = Craft::$app->fields->getFieldByHandle('wordpressUUID');
                        $uuidField = 'field_wordpressUUID_' . $uuidField->columnSuffix;
                        //
                        $fieldMap[$superTableHandle]['fields'][$fieldHandle]['options']['create'] = "0";
                        $fieldMap[$superTableHandle]['fields'][$fieldHandle]['options']['match'] = $uuidField;
                    }
                }
                $fieldItem = $superTable;
            }
        }

        $fieldMappings = $fieldMap;
        $fieldMappingsExtra = $fieldMap;

        $fieldDefinitions[$key]['fieldTarget'] = $finalFieldHandle;
        Craft::$app->cache->set($cacheKey, json_encode($fieldDefinitions), 0, new TagDependency(['tags' => 'migrate-from-wordpress']));
    }

    /**
     * Parse WordPress field values.
     *
     * @param array $contents
     * @param array $fieldValues
     * @param string $level
     * @param string $contentLanguage
     * @param string $entityType
     * @param array $fields
     * @param string $itemKey
     * @return void
     */
    public static function analyzeFieldValues(array $contents, array &$fieldValues, string $level, string $contentLanguage = null, string $entityType = null, array $fields = null, string $itemKey = null)
    {
        $wordpressURL = MigrateFromWordPressPlugin::$plugin->settings->wordpressURL;
        $level .= '-';
        $wordpressURL = MigrateFromWordPressPlugin::$plugin->settings->wordpressURL;
        $wordpressRestApiEndpoint = MigrateFromWordPressPlugin::$plugin->settings->wordpressRestApiEndpoint;
        $restApiAddress = $wordpressURL . '/' . $wordpressRestApiEndpoint;

        foreach ($contents as $key => $content) {
            if ($key == 'fields') {
                foreach ($content as $fieldname => $fieldItem) {
                    $value = null;
                    if (isset($fields[$fieldname]['convertTarget']) && $fields[$fieldname]['convertTarget'] == 'craft\fields\Assets') {
                        if (is_array($fieldItem['value'])) {
                            $values = $fieldItem['value'];
                            foreach ($values as $val) {
                                if (is_int($val)) {
                                    $response = Curl::sendToRestAPI($restApiAddress . '/media/' . $val);
                                    $response = json_decode($response);
                                    if (isset($response->guid->rendered)) {
                                        $value[] = $response->guid->rendered;
                                    } else {
                                        // TODO: show logs to users
                                        craft::dd('rest api does not return media for' . $val);
                                    }
                                } elseif ($val) {
                                    // TODO: show logs to users
                                    craft::dd('media id is not integer. probably ' . $fieldname . ' can not be converted to asset field');
                                }
                            }
                        } elseif ($fieldItem['value']) {
                            if (is_int($fieldItem['value'])) {
                                $response = Curl::sendToRestAPI($restApiAddress . '/media/' . $fieldItem['value']);
                                $response = json_decode($response);
                                if (isset($response->guid->rendered)) {
                                    $value = $response->guid->rendered;
                                } else {
                                    // TODO: show logs to users
                                    craft::dd('rest api does not return media for ' . $fieldItem['value']);
                                }
                            } elseif ($fieldItem['value']) {
                                // TODO: show logs to users
                                craft::dd('media id is not integer. probably ' . $fieldname . ' can not be converted to asset field');
                            }
                        }
                    } elseif (
                        isset($fields[$fieldname]['convertTarget']) &&
                        ($fields[$fieldname]['convertTarget'] == 'craft\fields\Dropdown' ||
                            $fields[$fieldname]['convertTarget'] == 'craft\fields\MultiSelect' ||
                            $fields[$fieldname]['convertTarget'] == 'craft\fields\Checkboxes' ||
                            $fields[$fieldname]['convertTarget'] == 'craft\fields\RadioButtons')
                    ) {
                        if (isset($fields[$fieldname]['containerTarget'])) {
                            $containerTarget = $fields[$fieldname]['containerTarget'];
                        } else {
                            $containerTarget = '';
                        }
                        $fieldsArray = FieldHelper::findField($fields[$fieldname]['fieldTarget'], null, $containerTarget);
                        if (isset($fieldsArray[0]['field'])) {
                            $field = $fieldsArray[0]['field'];
                            $options = $field->options;
                            $ops = ArrayHelper::getColumn($options, 'value');
                            if (is_array($fieldItem['value'])) {
                                $values = $fieldItem['value'];
                                foreach ($values as $val) {
                                    if (!in_array($val, $ops)) {
                                        $options[] = [
                                            'label' => (string) $val,
                                            'value' => (string) $val,
                                            'default' => 0,
                                        ];
                                    }
                                }
                            } elseif ($fieldItem['value']) {
                                if (!in_array($fieldItem['value'], $ops)) {
                                    $options[] = [
                                        'label' => (string) $fieldItem['value'],
                                        'value' => (string) $fieldItem['value'],
                                        'default' => 0,
                                    ];
                                }
                            }
                            $field->options = $options;
                            if ($options) {
                                // TODO: it seems saved options here won't apply immediately so feed should be run two times
                                Craft::$app->fields->saveField($field);
                            }
                            $value = $fieldItem['value'];
                        } elseif (isset($fieldsArray[0]['table'])) {
                            $table = $fieldsArray[0]['table'];
                            $options = $fieldsArray[0]['column']['options'];
                            $ops = ArrayHelper::getColumn($options, 'value');
                            if (is_array($fieldItem['value'])) {
                                $values = $fieldItem['value'];
                                foreach ($values as $val) {
                                    if (!in_array($val, $ops)) {
                                        $options[] = [
                                            'label' => (string) $val,
                                            'value' => (string) $val,
                                            'default' => 0,
                                        ];
                                    }
                                }
                            } elseif ($fieldItem['value']) {
                                if (!in_array($fieldItem['value'], $ops)) {
                                    $options[] = [
                                        'label' => (string) $fieldItem['value'],
                                        'value' => (string) $fieldItem['value'],
                                        'default' => 0,
                                    ];
                                }
                            }
                            $table->columns[$fieldsArray[0]['tableKey']]['options'] = $options;
                            if ($options) {
                                // TODO: it seems saved options here won't apply immediately so feed should be run two times
                                Craft::$app->fields->saveField($table);
                            }
                            $value = $fieldItem['value'];
                        }
                    } elseif (
                        isset($fields[$fieldname]['convertTarget']) &&
                        ($fields[$fieldname]['convertTarget'] == 'craft\fields\Categories'
                        )
                    ) {
                        $values = $fieldItem['value'];
                        foreach ($values as $val) {
                            if (is_int($val)) {
                                $response = Curl::sendToRestAPI($restApiAddress . '/categories/' . $val);
                                $response = json_decode($response);
                                if (isset($response->taxonomy) && $response->taxonomy == 'category') {
                                    if (isset($response->link)) {
                                        $value[] = $response->link;
                                    } else {
                                        craft::dd('response for category ' . $val . ' has not link');
                                    }
                                } else {
                                    // TODO: show logs to users
                                    craft::dd('rest api does not return category for' . $val);
                                }
                            } else {
                                // TODO: show logs to users
                                craft::dd('category id is not integer. probably ' . $fieldname . ' can not be converted to category field');
                            }
                        }
                    } elseif (
                        isset($fields[$fieldname]['convertTarget']) &&
                        ($fields[$fieldname]['convertTarget'] == 'craft\fields\Tags'
                        )
                    ) {
                        $values = $fieldItem['value'];
                        foreach ($values as $val) {
                            if (is_int($val)) {
                                $response = Curl::sendToRestAPI($restApiAddress . '/tags/' . $val);
                                $response = json_decode($response);
                                if (isset($response->taxonomy) && $response->taxonomy == 'post_tag') {
                                    if (isset($response->link)) {
                                        $value[] = $response->link;
                                    } else {
                                        craft::dd('response for tag ' . $val . ' has not link');
                                    }
                                } else {
                                    // TODO: show logs to users
                                    craft::dd('rest api does not return tag for' . $val);
                                }
                            } else {
                                // TODO: show logs to users
                                craft::dd('tag id is not integer. probably ' . $fieldname . ' can not be converted to tag field');
                            }
                        }
                    } elseif (isset($fieldItem['value'])) {
                        $fieldItemValues = $fieldItem['value'];
                        if (is_array($fieldItemValues)) {
                            foreach ($fieldItemValues as $fieldItemKey => $fieldItemValue) {
                                $value[$fieldItemKey] = $fieldItemValue;
                            }
                        } else {
                            $value = $fieldItemValues;
                        }
                    }

                    $fieldValue = [];
                    $fieldValue['value'] = $value;
                    $fieldValues[$fieldname] = $fieldValue;

                    if (
                        isset($fields[$fieldname]['config']['type'])
                    ) {
                        if (
                            in_array($fields[$fieldname]['config']['type'], self::$_parentFieldTypes)
                            // Gutenberg blocks are processed before so no need to process here
                            && $fields[$fieldname]['config']['type'] != 'gutenberg'
                        ) {
                            FieldHelper::analyzeFieldValues($fieldItem, $fieldValues, $level, $contentLanguage, null, $fields);
                        }
                    }
                }
            }
        }
    }

    /**
     * Get WordPress field settings.
     *
     * @param array $contents
     * @param string $itemKey
     * @param string $level
     * @param array $fieldDefinitions
     * @return array
     */
    public static function fieldOptions(array $contents, string $itemKey, string $level = '', array &$fieldDefinitions = []): array
    {
        $level .= '->';

        foreach ($contents as $key => $content) {
            if ($key == 'fields') {
                foreach ($content as $fieldname => $fieldItem) {
                    if (isset($fieldItem['config']['isAttribute'])) {
                        continue;
                    }

                    $fieldType = '';
                    $label = $fieldname;
                    $config = [];

                    if (isset($fieldItem['config']['type'])) {
                        $fieldType = $fieldItem['config']['type'];
                    }

                    if (isset($fieldItem['config']['label'])) {
                        $label = $fieldItem['config']['label'];
                    }

                    if (isset($fieldItem['config'])) {
                        $config = $fieldItem['config'];
                    }

                    $fieldDefinition = [];
                    $fieldDefinition['label'] = $level . ' ' . $label;

                    $fieldDefinition['wordpressHandle'] = $fieldname;
                    $fieldDefinition['handle'] = $fieldname;

                    if (isset($fieldItem['config']['name'])) {
                        $fieldDefinition['originalWordPressHandle'] = $fieldItem['config']['name'];
                    } else {
                        $fieldDefinition['originalWordPressHandle'] = $fieldname;
                    }
                    $fieldDefinition['type'] = $fieldType;

                    $convertTo = GeneralHelper::convertTo($fieldType);
                    $fieldDefinition['convertTo'] = $convertTo;
                    $fieldDefinition['config'] = $config;

                    $fieldDefinition['disabledConvert'] = false;

                    if (in_array($fieldType, self::$_parentFieldTypes)) {
                        $fieldDefinition['disabledConvert'] = true;
                    }

                    $fieldDefinitions[$fieldDefinition['wordpressHandle']] = $fieldDefinition;

                    if (
                        in_array($fieldType, self::$_parentFieldTypes)
                    ) {
                        FieldHelper::fieldOptions($fieldItem, $itemKey, $level, $fieldDefinitions);
                    }
                }
            }
        }
        return $fieldDefinitions;
    }

    /**
     * filter WordPress fields by type
     *
     * @param array|null $fields
     * @param string $type Craft type name
     * @return array
     */
    public static function filterFieldsByType(?array $fields, string $type): array
    {
        $nameFields[''] = Craft::t('migrate-from-wordpress', 'Select One');
        if ($fields) {
            foreach ($fields as $key => $field) {
                if (in_array($type, $field['convertTo'])) {
                    $nameField = [];
                    $nameField['text'] = $key;
                    $nameField['value'] = $key;
                    $nameFields[$key] = $key;
                }
            }
        }
        return $nameFields;
    }

    /**
     * Generate label and migration info for field definition table
     *
     * @param array $fieldDefinitions
     * @return void
     */
    public static function hookWordPressLabelAndInfo(array $fieldDefinitions)
    {
        foreach ($fieldDefinitions as $fieldDefinition) {
            $label = '<font color=green>' . $fieldDefinition['label'] . '</font>';

            Craft::$app->view->hook($fieldDefinition['wordpressHandle'], function () use ($label) {
                return $label;
            });
        }
    }
}
