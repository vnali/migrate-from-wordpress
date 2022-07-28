<?php

namespace vnali\migratefromwordpress\helpers;

class TableHelper
{
    /**
     * Get Craft table column type based on field type.
     *
     * @param string $fieldType
     * @return string|null
     */
    public static function fieldType2ColumnType(string $fieldType)
    {
        switch ($fieldType) {
            case 'craft\fields\Color':
                $columnType = 'color';
                break;

            case 'craft\fields\Date':
                $columnType = 'date';
                break;

            case 'craft\fields\Url':
                $columnType = 'url';
                break;

            case 'craft\fields\Email':
                $columnType = 'email';
                break;

            case 'craft\fields\PlainText':
                $columnType = 'singleline';
                break;

            case 'craft\ckeditor\Field':
                $columnType = 'multiline';
                break;

            case 'craft\redactor\Field':
                $columnType = 'multiline';
                break;

            case 'craft\fields\Number':
                $columnType = 'number';
                break;

            case 'craft\fields\Dropdown':
                $columnType = 'select';
                break;

            case 'craft\fields\Lightswitch':
                $columnType = 'lightswitch';
                break;

            case 'craft\fields\Number':
                $columnType = 'number';
                break;

            case 'craft\fields\Time':
                $columnType = 'time';
                break;
            
            case 'craft\fields\RadioButtons':
                $columnType = 'select';
                break;

            default:
                $columnType = null;
                break;
        }
        return $columnType;
    }
}
