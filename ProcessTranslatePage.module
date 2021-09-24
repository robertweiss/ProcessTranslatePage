<?php namespace ProcessWire;

/**
 * Translate page via fluency
 *
 * Translates all textfields on a page (including repeater(-matrix), file descriptions and functional fields)
 *
 */
class ProcessTranslatePage extends Process implements Module {
    private $sourceLang;
    private $targetLang;
    private $sourceLangPage;
    private $targetLangPage;
    private $excludedTemplates = [];
    private $adminTemplates = ['admin', 'language', 'user'];
    private $overwriteExistingTranslation;

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

    public static function getModuleInfo() {
        return [
            'title' => 'TranslatePage (via Fluency)',
            'summary' => 'Translates all textfields on a page (including repeater(-matrix), file descriptions and functional fields)',
            'version' => 1,
            'author' => 'Robert Weiss',
            'href' => 'http://modules.processwire.com/',
            'singular' => true,
            'autoload' => true,
            'requires' => 'Fluency',
        ];
    }

    // https://processwire.com/talk/topic/12168-how-to-add-additional-button-next-to-the-save-button-on-top-backend/?do=findComment&comment=112883
    public function init() {
        $this->addHookAfter("ProcessPageEdit::buildForm", $this, "addButton");
        $this->addHookAfter("Pages::saved", $this, "hookPageSave");

        // If post params have translate post value, trigger page save
        if ($this->input->post->save_and_translate) {
            $this->input->post->submit_save = 1;
        }
    }

    public function hookPageSave($event) {
        /** @var Page $page */
        $page = $event->arguments("page");

        // Set fluency language codes, the corresponding pw languages and excluded templates
        // TODO: move this part to module config
        $this->sourceLangCode = 'DE';
        $this->targetLangCode = 'EN-GB';
        $this->sourceLangPage = languages()->get('default');
        $this->targetLangPage = languages()->get('en');
        $this->excludedTemplates = [];
        $this->overwriteExistingTranslation = false;

        // Only start translating if post variable is set
        if (!$this->input->post->save_and_translate) {
            return;
        }

        // Throttle translations (only triggers every ten seconds)
        if ($this->page->modified > (time() - 10)) {
            $this->error('Translation is only allowed once every ten seconds. Please wait some time and try again.');

            return;
        }

        // Let’s go!
        $this->processFields($page);
    }

    public function addButton($event) {
        $page = $event->object->getPage();

        // Don’t show button in excluded or admin templates
        if (in_array($page->template->name, array_merge($this->adminTemplates, $this->excludedTemplates))) {
            return;
        }

        if (!user()->hasPermission('fluency-translate')) {
            return;
        }

        $form = $event->return;

        $button = $this->modules->InputfieldSubmit;
        $button->attr('name', 'save_and_translate');
        $button->attr('value', __('Save and translate'));
        $button->class .= ' ui-priority-secondary head_button_clone';
        $form->insertAfter($button, $form->get('submit_save'));
    }

    private function translate(String $value): string {
        $fluency = modules()->get('Fluency');
        $result = $fluency->translate($this->sourceLangCode, $value, $this->targetLangCode);
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
        $value = $page->getLanguageValue($this->sourceLangPage, $fieldName);
        // If field is empty or translation already exists und should not be overwritten, return
        if (!$value || ($page->getLanguageValue($this->targetLangPage, $fieldName) != '' && !$this->overwriteExistingTranslation)) {
            return;
        }
        $result = $this->translate($value);
        $page->setLanguageValue($this->targetLangPage, $fieldName, $result);
        $page->save($fieldName);
    }

    private function processFileField(Field $field, Page $page) {
        /** @var Field $field */
        $field = $page->$field;
        if (!$field->count()) {
            return;
        }

        foreach ($field as $item) {
            $value = $item->description;
            // If no description set or translated description already exists und should not be overwritten, continue
            if (!$value || ($item->description($this->targetLangPage) != '' && !$this->overwriteExistingTranslation)) {
                continue;
            }
            $result = $this->translate($value);
            $item->description($this->targetLangPage, $result);
            $item->save();
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
            $enFieldName = $name.'.'.$this->targetLangPage->id;
            // If translation already exists und should not be overwritten, continue
            if ($page->$field->$enFieldName != '' && !$this->overwriteExistingTranslation) {
                continue;
            }
            $result = $this->translate($value);
            $page->$field->$enFieldName = $result;
        }
        $page->save($field->name);
    }
}
