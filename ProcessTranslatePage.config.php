<?php namespace ProcessWire;

class ProcessTranslatePageConfig extends ModuleConfig {
    // Parts of the code are adopted from the Jumplinks module, thx!
    // Copyright (c) 2016-17, Mike Rockett
    protected function buildInputField($fieldNameId, $meta) {
        $field = wire('modules')->get($fieldNameId);

        foreach ($meta as $metaNames => $metaInfo) {
            $metaNames = explode('+', $metaNames);
            foreach ($metaNames as $metaName) {
                $field->$metaName = $metaInfo;
            }
        }

        return $field;
    }

    public function getDefaults() {
        return [
            'sourceLanguage' => wire('languages')->get('default')->id,
            'excludedTemplates' => [],
            'excludedFields' => [],
            'excludedLanguages' => [],
            'writemode' => 'empty',
            'showSingleTargetLanguageButtons' => false,
        ];
    }

    private function getLanguageOptions() {
        $configuredLanguages = wire('modules')->get('Fluency')->getConfiguredLanguages()->languages;

        return array_reduce($configuredLanguages, function($options, $language) {
            $options[$language->id] = $language->title;

            return $options;
        }, []);
    }

    private function getTemplateOptions() {
        $excludedTemplatesOptions = [];
        if (wire('templates')) {
            foreach (wire('templates') as $template) {
                if ($template->flags && $template->flags === Template::flagSystem) {
                    continue;
                }
                $label = $template->label ? $template->label.' ('.$template->name.')' : $template->name;
                $excludedTemplatesOptions[$template->name] = $label;
            }
        }

        return $excludedTemplatesOptions;
    }

    private function getFieldOptions() {
        $excludedFieldsOptions = [];
        if (wire('fields')) {
            foreach (wire('fields') as $field) {
                if ($field->flags && $field->flags === Field::flagSystem) {
                    continue;
                }
                $label = $field->label ? $field->label.' ('.$field->name.')' : $field->name;
                $excludedFieldsOptions[$field->name] = $label;
            }
        }

        return $excludedFieldsOptions;
    }

    public function getInputFields() {
        $inputfields = parent::getInputfields();

        $inputfields->add(
            $this->buildInputField('InputfieldSelect', [
                'name+id' => 'sourceLanguage',
                'label' => $this->_('Source Language'),
                'description' => $this->_('The language which will be used to translate from. If no selection is made, the default language will be used'),
                'notes' => $this->_('Languages need to be defined in Fluency config first'),
                'options' => $this->getLanguageOptions(),
                'columnWidth' => 33,
            ])
        );

        $inputfields->add(
            $this->buildInputField('InputfieldRadios', [
                'name+id' => 'writemode',
                'label' => $this->_('Write mode'),
                'notes' => $this->_('Caution: the »Changed fields« option support is currently only one level deep. If you change any value inside a Repeater (Matrix) or FieldsetPage field, the complete field will be translated'),
                'options' => [
                    'empty' => $this->_('Translate only if target field is empty'),
                    'changed' => $this->_('Translate only changed fields'),
                    'all' => $this->_('Overwrite all target fields'),
                ],
                'columnWidth' => 33,
            ])
        );

        $inputfields->add(
            $this->buildInputField('InputfieldCheckbox', [
                'name+id' => 'showSingleTargetLanguageButtons',
                'label' => $this->_('Show single target language buttons'),
                'description' => $this->_('If checked, the save dropdown will add one button for each allowed target language instead of one button for all languages combined.'),
                'columnWidth' => 34,
            ])
        );

        $inputfields->add(
            $this->buildInputField('InputfieldASMSelect', [
                'name+id' => 'excludedTemplates',
                'label' => $this->_('Excluded Templates'),
                'description' => $this->_('Pages with these templates will not display the save + translate option in the save dropdown'),
                'options' => $this->getTemplateOptions(),
                'columnWidth' => 33,
            ])
        );

        $inputfields->add(
            $this->buildInputField('InputfieldASMSelect', [
                'name+id' => 'excludedFields',
                'label' => $this->_('Excluded Fields'),
                'description' => $this->_('Fields that will be ignored when translating'),
                'options' => $this->getFieldOptions(),
                'columnWidth' => 33,
            ])
        );

        $inputfields->add(
            $this->buildInputField('InputfieldASMSelect', [
                'name+id' => 'excludedLanguages',
                'label' => $this->_('Excluded Languages'),
                'description' => $this->_('Target languages that will be ignored when translating'),
                'options' => $this->getLanguageOptions(),
                'columnWidth' => 34,
            ])
        );

        return $inputfields;
    }
}
