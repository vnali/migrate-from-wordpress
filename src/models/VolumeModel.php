<?php

namespace vnali\migratefromwordpress\models;

use Craft;
use craft\base\Model;
use craft\records\Volume as VolumeRecord;

class VolumeModel extends Model
{
    /**
     * @var int
     */
    public $volumeId;

    /**
     * @var string
     */
    public $titleOption;

    /**
     * @var bool
     */
    public $useAltNativeField;

    public function init(): void
    {
        parent::init();
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = ['volumeId', 'required', 'on' => 'convert-file'];
        $rules[] = [
            'volumeId', 'exist', 'targetClass' => VolumeRecord::class, 'targetAttribute' => ['volumeId' => 'id'],
            'skipOnEmpty' => false, 'on' => ['convert-file'],
        ];
        $rules[] = [
            'titleOption', 'in', 'range' => ['media-alt', 'media-caption', 'media-title', 'auto-generate'], 'skipOnEmpty' => false, 'on' => ['convert-file'],
        ];
        $rules[] = [['useAltNativeField'], 'in', 'range' => ['0', '1'], 'on' => 'convert-file'];
        $rules[] = ['volumeId', function($attribute, $params, $validator) {
            $cache = Craft::$app->getCache();
            if (
                $cache->get('migrate-from-wordpress-file-posted-fields-' . $this->volumeId) ||
                $cache->get('migrate-from-wordpress-file-posted-condition-' . $this->volumeId)
            ) {
                $this->addError('volumeId', 'you selected this volume before');
            }
        }, 'skipOnEmpty' => false, 'on' => 'convert-file'];
        return $rules;
    }

    /**
     * Prepare volume for drop down.
     *
     * @return array
     */
    public static function volumeList(): array
    {
        $volumes = [];
        $volumes[] = ['value' => '', 'label' => Craft::t('migrate-from-wordpress', 'select volume')];
        foreach (Craft::$app->volumes->getAllVolumes()  as $volumeItem) {
            $volume = [];
            $volume['value'] = $volumeItem->id;
            $volume['label'] = $volumeItem->name;
            $volumes[] = $volume;
        }
        return $volumes;
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        $labels = parent::attributeLabels();

        $labels['volumeId'] = Craft::t('migrate-from-wordpress', 'Volume');
        $labels['titleOption'] = Craft::t('migrate-from-wordpress', 'Title Option');

        return $labels;
    }
}
