<?php namespace ProcessWire;

use Fluency\App\{ FluencyErrors, FluencyLocalization };
use Fluency\DataTransferObjects\ConfiguredLanguageData;

/**
 * Translate all textfields on a page via Fluency
 *
 */
class ProcessTranslatePage extends Process implements Module {
    private $fluency;
    private $sourceLanguage;
    private $targetLanguages = [];
    private $excludedTemplates = [];
    private $excludedFields = [];
    private $excludedLanguages = [];
    private $adminTemplates = ['admin', 'language', 'user', 'permission', 'role'];
    private $writemode;
    private $showSingleTargetLanguageButtons;
    private $translatedFieldsCount = 0;
    private $translatedFields = [];
    private $changedFields = [];

    private $localizedStrings;
    private $languagePages = null;
    private $translationErrors = [];

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

    public function init() {
        if (!$this->user->hasPermission('fluency-translate')) {
            return;
        }

        $this->initSettings();
        $this->addHookAfter("ProcessPageEdit::getSubmitActions", $this, "addDropdownOption");
        $this->addHookAfter("Pages::saved", $this, "hookPageSave");

        parent::init();
    }

    public function initSettings() {
        // Set (user-)settings
        $this->sourceLanguage = $this->get('sourceLanguage');
        $this->excludedTemplates = $this->get('excludedTemplates');
        $this->excludedFields = $this->get('excludedFields');
        $this->excludedLanguages = $this->get('excludedLanguages');
        $this->writemode = $this->get('writemode');
        $this->showSingleTargetLanguageButtons = !!$this->get('showSingleTargetLanguageButtons');
        $this->throttleSave = 5;

        $this->fluency = $this->modules->get('Fluency');

        $this->localizedStrings = (object) [
            'translate' => FluencyLocalization::get('inputfieldTranslateButtons', 'translate'),
            'translated' => FluencyLocalization::get('standaloneTranslator', 'fieldLabelTranslated'),
            'rateLimitError' => FluencyLocalization::get('errors', FluencyErrors::RATE_LIMIT_EXCEEDED),
        ];

        $this->setLanguages();
    }

    public function hookPageSave($event) {
        /** @var Page $page */
        $page = $event->arguments('page');
        // We need the changed field names as a simple array
        $this->changedFields = array_values($event->arguments(1));

        $afterSubmitAction = $this->input->post->_after_submit_action;

        // Only start translating if post variable is set
        if (!$afterSubmitAction || !str_starts_with($afterSubmitAction, 'save_and_translate')) {
            return;
        }

        // Check if post variable has an appended single target language code
        if (preg_match('/(save_and_translate_)[0-9]{4,}/', $afterSubmitAction)) {

            // Selected target language is the last part of the post variable
            $singleTargetLanguage = preg_replace('/[^0-9]/', '', $afterSubmitAction);

            // Filter all allowed target languages for the selected language name
            $this->targetLanguages = array_filter(
                $this->targetLanguages,
                fn ($targetLanguage) => $targetLanguage->id == $singleTargetLanguage
            );
        }

        // Throttle translations (only triggers every after a set amount of time)
        if ($this->page->modified > (time() - $this->throttleSave)) {
            $this->error($this->localizedStrings->rateLimitError);

            return;
        }

        // Let’s go!
        $this->processFields($page);

        if ($this->translationErrors) {
            $translationErrors = array_unique($this->translationErrors);
            $translationErrors = implode(', ', $this->translationErrors);

            $this->error($translationErrors);
        }

        if ($this->translatedFieldsCount) {
            $this->message("{$this->localizedStrings->translated}: " . implode(', ', $this->translatedFields));
        }
    }

    public function addDropdownOption($event) {
        /** @var Page $page */
        $page = $this->pages->get($this->input->get->id);

        // Don’t show option in excluded or admin templates
        if (in_array($page->template->name, array_merge($this->adminTemplates, $this->excludedTemplates))) {
            return;
        }

        $actions = $event->return;

        $label = "%s + {$this->localizedStrings->translate}";

        // If single buttons are set, add one button for each target language
        if ($this->showSingleTargetLanguageButtons) {
            foreach ($this->targetLanguages as $targetLanguage) {
                $actions[] = [
                    'value' => 'save_and_translate_' . $targetLanguage->id,
                    'icon' => 'language',
                    'label' => $label . ': ' . $this->sourceLanguagetitle . ' &rarr; ' . $targetLanguage->title,
                ];
            }
        // Else add only one button to translate to all target languages
        } else {
            $actions[] = [
                'value' => 'save_and_translate',
                'icon' => 'language',
                'label' => $label,
            ];
        }

        $event->return = $actions;
    }

    public function translatePageTree(Page $page, bool $includeHidden = true) {
        $this->initSettings();
        // Only process page if template is valid
        if (!in_array($page->template->name, array_merge($this->adminTemplates, $this->excludedTemplates))) {
            $this->processFields($page, false);
            echo "Process page {$page->title} ({$page->id})\n";
        } else {
            echo "Ignore page {$page->title} ({$page->id})\n";
        }

        $selector = ($includeHidden) ? 'include=hidden' : '';

        // Iterate through all children and process them recursively
        foreach ($page->children($selector) as $item) {
            $this->translatePageTree($item, $includeHidden);
        }
    }

    private function setLanguages() {
        $allLanguages = $this->fluency->getConfiguredLanguages();

        $this->sourceLanguage = $allLanguages->getByProcessWireId($this->sourceLanguage);

        $this->targetLanguages = array_filter($allLanguages->languages, function($language) {
            $id = $language->id;

            return !in_array($id, $this->excludedLanguages) && $id !== $this->sourceLanguage->id;
        });
    }

    private function getLanguagePage(ConfiguredLanguageData $language): Page {
        $this->languagePages ??= wire('languages');

        return $this->languagePages->get($language->id);
    }

    private function translate(string $value, ConfiguredLanguageData $targetLangauge): ?string {
        $sourceCode = $this->sourceLanguage->engineLanguage->sourceCode;
        $targetCode = $targetLangauge->engineLanguage->targetCode;

        $result = $this->fluency->translate($sourceCode, $targetCode, $value);

        if ($result->error) {
            $this->translationErrors[] = $result->message;

            return null;
        }

        $resultText = $result->translations[0];

        return $resultText;
    }

    private function processFields($page, $isPageWhichSaveWasHookedOn = true) {
        $page->of(false);
        $fields = $page->template->fields;

        if (get_class($page) === 'ProcessWire\RepeaterMatrixPage') {
            $fields = $page->matrix('fields');
        }

        foreach ($fields as $field) {
            // Ignore fields that are set as excluded in user settings
            if (in_array($field->name, $this->excludedFields)) {
                continue;
            }

            // If only changed fields should be translated, check if we process the hooked page
            // Changes are only listed for the hooked page itself atm, not for ›subpages‹ (repeater, fieldsetpage e.g.)
            if ($this->writemode == 'changed' && $isPageWhichSaveWasHookedOn) {
                if (!in_array($field->name, $this->changedFields)) {
                    continue;
                }
            }

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

            if ($shortType == 'Table') {
                $this->processTableField($field, $page);
                continue;
            }

            if ($shortType == 'Combo') {
                $this->processComboFields($field, $page);
                continue;
            }
        }
    }

    private function processTextField(Field $field, Page $page) {
        $fieldName = $field->name;

        $value = $page->getLanguageValue($this->sourceLanguage->id, $fieldName);
        $countField = false;

        foreach ($this->targetLanguages as $targetLanguage) {
            $targetId = $targetLanguage->id;

            // If field is empty or translation already exists and should not be overwritten, return
            if (!$value || ($page->getLanguageValue($targetId, $fieldName) != '' && $this->writemode == 'empty')) {
                continue;
            }
            $result = $this->translate($value, $targetLanguage);

            if (!$result) {
                continue;
            }

            $page->setLanguageValue($targetId, $fieldName, $result);
            $countField = true;
        }

        $page->save($fieldName);

        if ($countField) {
            $this->translatedFields[] = $field->label;
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
                $targetId = $targetLanguage->id;

                // If no description set or translated description already exists and should not be overwritten, continue
                if (!$value || ($item->description($targetId) != '' && $this->writemode == 'empty')) {
                    continue;
                }
                $result = $this->translate($value, $targetLanguage);

                if (!$result) {
                    continue;
                }

                $item->description($this->getLanguagePage($targetLanguage), $result);
                $countField = true;
            }
            $item->save();
            if ($countField) {
                $this->translatedFields[] = $field->label;
                $this->translatedFieldsCount++;
            }
        }
    }

    private function processRepeaterField($field, Page $page) {
        foreach ($page->$field as $item) {
            $this->processFields($item, false);
        }
    }

    private function processFieldsetPage(Field $field, Page $page) {
        $this->processFields($page->$field, false);
    }

    private function processFunctionalField(Field $field, Page $page) {
        foreach ($page->$field as $name => $value) {
            // Ignore fallback values (starting with a dot)
            if (strpos($name, '.') === 0) {
                continue;
            }
            $countField = false;

            foreach ($this->targetLanguages as $targetLanguage) {
                $targetFieldName = $name.'.'.$targetLanguage->id;
                // If translation already exists and should not be overwritten, continue
                if ($page->$field->$targetFieldName != '' && $this->writemode == 'empty') {
                    continue;
                }
                $result = $this->translate($value, $targetLanguage);

                if (!$result) {
                    continue;
                }

                $page->$field->$targetFieldName = $result;
                $countField = true;
            }
            if ($countField) {
                $this->translatedFields[] = $field->label;
                $this->translatedFieldsCount++;
            }
        }
        $page->save($field->name);
    }

    private function processTableField(Field $field, Page $page) {
        $fieldName = $field->name;

        foreach ($page->$field as $row) {
            /** @var TableRow $row */
            foreach ($row as $item) {
                if ($item instanceof LanguagesPageFieldValue) {
                    /** @var LanguagesPageFieldValue $item */
                    $value = $item->getLanguageValue($this->sourceLanguage->id);
                    $countField = false;

                    foreach ($this->targetLanguages as $targetLanguage) {
                        $targetId = $targetLanguage->id;

                        // If field is empty or translation already exists and should not be overwritten, return
                        if (!$value || ($item->getLanguageValue($targetId) != '' && $this->writemode == 'empty')) {
                            continue;
                        }
                        $result = $this->translate($value, $targetLanguage);


                        if (!$result) {
                            continue;
                        }

                        $item->setLanguageValue($targetId, $result);
                        $countField = true;
                    }

                    if ($countField) {
                        $this->translatedFields[] = $field->label;
                        $this->translatedFieldsCount++;
                    }
                }
            }
        }
        $page->save($fieldName);
    }

    private function processComboFields(Field $field, Page $page) {
        $comboFieldsName = $field->name;
        foreach ($page->$field as $comboFieldName => $comboField) {
            if (get_class($comboField) !== 'ProcessWire\ComboLanguagesValue') {
                continue;
            }

            $this->processComboField($comboField, $comboFieldName, $comboFieldsName, $page);
        }
    }

    private function processComboField(
        ComboLanguagesValue $comboFieldValue,
        String $comboFieldName,
        String $comboFieldsName,
        Page $page
    ) {
        $value = $page->$comboFieldsName->$comboFieldName->getLanguageValue($this->sourceLanguage->id);
        $countField = false;

        foreach ($this->targetLanguages as $targetLanguage) {
            $targetId = $targetLanguage->id;

            // If field is empty or translation already exists and should not be overwritten, return
            if (!$value || ($page->$comboFieldsName->$comboFieldName->getLanguageValue($targetId) != '' && $this->writemode == 'empty')) {
                continue;
            }
            $result = $this->translate($value, $targetLanguage);

            if (!$result) {
                continue;
            }

            $page->$comboFieldsName->$comboFieldName->setLanguageValue($targetId, $result);
            $countField = true;
        }

        $page->save($comboFieldsName);
        if ($countField) {
            $this->translatedFields[] = $page->$comboFieldsName->getField()->getSubfield($comboFieldName)->label;
            $this->translatedFieldsCount++;
        }
    }
}
