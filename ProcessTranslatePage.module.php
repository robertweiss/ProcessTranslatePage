<?php namespace ProcessWire;

/**
 * Translate page via fluency
 *
 * Translates all textfields on a page (including repeater(-matrix), file descriptions and functional fields)
 *
 */
class ProcessTranslatePage extends Process implements Module {
    private $fluency;
    private $sourceLanguage;
    private $targetLanguages = [];
    private $excludedTemplates = [];
    private $adminTemplates = ['admin', 'language', 'user', 'permission', 'role'];
    private $overwriteExistingTranslation;
    private $translatedFieldsCount = 0;

    private $textFieldTypes = [
        'PageTitleLanguage',
        'TextLanguage',
        'TextareaLanguage',
    ];

    private $descriptionFieldTypes = [
        'File',
        'Image',
    ];

    private $repeaterFieldTypes = [
        'Repeater',
        'RepeaterMatrix',
    ];

    // https://processwire.com/talk/topic/12168-how-to-add-additional-button-next-to-the-save-button-on-top-backend/?do=findComment&comment=112883
    public function init() {
        if (!$this->user->hasPermission('fluency-translate')) {
            return;
        }

        $this->addHookAfter("ProcessPageEdit::getSubmitActions", $this, "addDropdownOption");
        $this->addHookAfter("Pages::saved", $this, "hookPageSave");

        // Set (user-)settings
        $this->excludedTemplates = $this->get('excludedTemplates');
        $this->overwriteExistingTranslation = !!$this->get('overwriteExistingTranslation');
        $this->throttleSave = 5;

        parent::init();
    }

    public function hookPageSave($event) {
        /** @var Page $page */
        $page = $event->arguments("page");

        // Only start translating if post variable is set
        if ($this->input->post->_after_submit_action != 'save_and_translate') {
            return;
        }

        // Throttle translations (only triggers every after a set amount of time)
        if ($this->page->modified > (time() - $this->throttleSave)) {
            $this->error(__('Please wait some time before you try to translate again.'));

            return;
        }

        // Set fluency languages
        $this->fluency = $this->modules->get('Fluency');
        $this->setLanguages();

        // Let’s go!
        $this->processFields($page);
        $this->message($this->translatedFieldsCount . __(' fields translated.'));
    }

    public function addDropdownOption($event) {
        /** @var Page $page */
        $page = $this->pages->get($this->input->get->id);

        // Don’t show option in excluded or admin templates
        if (in_array($page->template->name, array_merge($this->adminTemplates, $this->excludedTemplates))) {
            return;
        }

        $actions = $event->return;
        $actions[] = [
            'value' => 'save_and_translate',
            'icon' => 'language',
            'label' => __('Save + Translate'),
        ];
        $event->return = $actions;
    }

    private function setLanguages() {
        // 1022 is ID of default language
        $this->sourceLanguage = [
            'page' => $this->languages->get(1022),
            'code' => $this->fluency->data['pw_language_1022']
        ];

        foreach ($this->fluency->data as $key => $data) {
            // Ignore non language keys and default language key
            if (strpos($key, 'pw_language_') !== 0 || $key === 'pw_language_1022') {
                continue;
            }
            $this->targetLanguages[] = [
                'page' => $this->languages->get(str_replace('pw_language_', '', $key)),
                'code' => $data
            ];
        }
    }

    private function translate(string $value, string $targetLanguageCode): string {
        if (!$targetLanguageCode) {
            return '';
        }
        $result = $this->fluency->translate($this->sourceLanguage['code'], $value, $targetLanguageCode);
        $resultText = $result->data->translations[0]->text;

        return $resultText;
    }

    private function processFields($page) {
        $page->of(false);
        $fields = $page->template->fields;

        if (get_class($page) === 'ProcessWire\RepeaterMatrixPage') {
            $fields = $page->matrix('fields');
        }

        foreach ($fields as $field) {
            // e.g. Processwire/FieldtypePageTitleLanguage -> PageTitleLanguage
            $shortType = str_replace('ProcessWire/', '', $field->type);
            $shortType = str_replace('Fieldtype', '', $shortType);

            if (in_array($shortType, $this->textFieldTypes)) {
                $this->processTextField($field, $page);
                continue;
            }

            if (in_array($shortType, $this->descriptionFieldTypes)) {
                $this->processFileField($field, $page);
                continue;
            }

            if (in_array($shortType, $this->repeaterFieldTypes)) {
                $this->processRepeaterField($field, $page);
                continue;
            }

            if ($shortType == 'FieldsetPage') {
                $this->processFieldsetPage($field, $page);
                continue;
            }

            if ($shortType == 'Functional') {
                $this->processFunctionalField($field, $page);
                continue;
            }
        }
    }

    private function processTextField(Field $field, Page $page) {
        $fieldName = $field->name;
        $value = $page->getLanguageValue($this->sourceLanguage['page'], $fieldName);
        $countField = false;

        foreach ($this->targetLanguages as $targetLanguage) {
            // If field is empty or translation already exists und should not be overwritten, return
            if (!$value || ($page->getLanguageValue($targetLanguage['page'], $fieldName) != '' && !$this->overwriteExistingTranslation)) {
                continue;
            }
            $result = $this->translate($value, $targetLanguage['code']);
            $page->setLanguageValue($targetLanguage['page'], $fieldName, $result);
            $countField = true;
        }
        $page->save($fieldName);
        if ($countField) {
            $this->translatedFieldsCount++;
        }
    }

    private function processFileField(Field $field, Page $page) {
        /** @var Field $field */
        $field = $page->$field;
        if (!$field->count()) {
            return;
        }
        $countField = false;

        foreach ($field as $item) {
            $value = $item->description;

            foreach ($this->targetLanguages as $targetLanguage) {
                // If no description set or translated description already exists und should not be overwritten, continue
                if (!$value || ($item->description($targetLanguage['page']) != '' && !$this->overwriteExistingTranslation)) {
                    continue;
                }
                $result = $this->translate($value, $targetLanguage['code']);
                $item->description($targetLanguage['page'], $result);
                $countField = true;
            }
            $item->save();
            if ($countField) {
                $this->translatedFieldsCount++;
            }
        }
    }

    private function processRepeaterField(RepeaterField $field, Page $page) {
        foreach ($page->$field as $item) {
            $this->processFields($item);
        }
    }

    private function processFieldsetPage(Field $field, Page $page) {
        $this->processFields($page->$field);
    }

    private function processFunctionalField(Field $field, Page $page) {
        foreach ($page->$field as $name => $value) {
            // Ignore fallback values (starting with a dot)
            if (strpos($name, '.') === 0) {
                continue;
            }
            $countField = false;

            foreach ($this->targetLanguages as $targetLanguage) {
                $targetFieldName = $name.'.'.$targetLanguage['page']->id;
                // If translation already exists und should not be overwritten, continue
                if ($page->$field->$targetFieldName != '' && !$this->overwriteExistingTranslation) {
                    continue;
                }
                $result = $this->translate($value, $targetLanguage['code']);
                $page->$field->$targetFieldName = $result;
                $countField = true;
            }
            if ($countField) {
                $this->translatedFieldsCount++;
            }
        }
        $page->save($field->name);
    }
}
