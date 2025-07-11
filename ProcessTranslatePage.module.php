<?php namespace ProcessWire;

require(__DIR__.'/vendor/autoload.php');
require(__DIR__.'/TranslateGlossary.php');

/**
 * Translate all textfields on a page via DeepL
 *
 */
class ProcessTranslatePage extends Process implements Module {
    public ?\DeepL\Translator $deepL;
    private ?TranslateGlossary $glossaryInstance;
    private ?\DeepL\MultilingualGlossaryInfo $glossary = null;
    private ?string $deepLApiKey;
    private ?string $deepLGlossaryId;
    private ?string $sourceLanguageName;
    private Language $sourceLanguage;
    private array $targetLanguages = [];
    private array $excludedTemplates = [];
    private array $excludedFields = [];
    private array $excludedLanguages = [];
    private array $adminTemplates = ['admin', 'language', 'user', 'permission', 'role'];
    private string $writemode;
    private bool $showSingleTargetLanguageButtons;
    private int $throttleSave;
    private int $translatedFieldsCount = 0;
    private array $changedFields = [];
    private Field $currentField;

    private array $textFieldTypes = [
        'PageTitleLanguage',
        'TextLanguage',
        'TextareaLanguage',
    ];

    private array $descriptionFieldTypes = [
        'File',
        'Image',
    ];

    private array $repeaterFieldTypes = [
        'Repeater',
        'RepeaterMatrix',
        'RockPageBuilder',
    ];

    public function ___install(): void {
        $fields = wire('fields');
        $languageTemplate = wire('templates')->get('name=language');

        if (!$fields->get('translate_locale')) {
            $field = new Field;
            $field->type = $this->modules->get("FieldtypeText");
            $field->name = "translate_locale";
            $field->label = $this->_('Locale');
            $field->description = $this->_('Used for DeepL translations. Valid values: https://developers.deepl.com/docs/getting-started/supported-languages');
            $field->columnWidth = 50;
            $field->save();
        }

        if (!$fields->get('translate_glossary')) {
            $field = new Field;
            $field->type = $this->modules->get("FieldtypeTextArea");
            $field->name = "translate_glossary";
            $field->label = $this->_('Glossary');
            $field->description = $this->_('Custom DeepL Glossary. One translation pair per line. Pairs need to be divided by two equal signs (==) in order to be recognized.');
            $field->notes = $this->_('Glossary is only used in target languages (Source language word==Target language word).');
            $field->columnWidth = 50;
            $field->save();
        }

        if (!$languageTemplate->fieldgroup->get('translate_locale')) {
            $field = $fields->get('translate_locale');
            $prevField = $languageTemplate->fieldgroup->get('title');
            $languageTemplate->fieldgroup->insertAfter($field, $prevField);
            $languageTemplate->fieldgroup->save();
        }

        if (!$languageTemplate->fieldgroup->get('translate_glossary')) {
            $field = $fields->get('translate_glossary');
            $prevField = $languageTemplate->fieldgroup->get('translate_locale');
            $languageTemplate->fieldgroup->insertAfter($field, $prevField);
            $languageTemplate->fieldgroup->save();
        }

        parent::___install();
    }

    public function ___uninstall(): void {
        $fields = wire('fields');
        $languageTemplate = wire('templates')->get('name=language');
        $languages = wire('languages');

        if ($fields->get('translate_locale')) {
            $isEmpty = false;
            foreach ($languages as $language) {
                if ($language->translate_locale !== '') {
                    $isEmpty = true;
                    break;
                }
            }

            if ($isEmpty) {
                $field = $fields->get('translate_locale');
                $languageTemplate->fieldgroup->remove($field);
                $languageTemplate->fieldgroup->save();
                $fields->delete($field);
            }
        }

        if ($fields->get('translate_glossary')) {
            $isEmpty = false;
            foreach ($languages as $language) {
                if ($language->translate_glossary !== '') {
                    $isEmpty = true;
                    break;
                }
            }

            if ($isEmpty) {
                $field = $fields->get('translate_glossary');
                $languageTemplate->fieldgroup->remove($field);
                $languageTemplate->fieldgroup->save();
                $fields->delete($field);
            }
        }

        parent::___uninstall();
    }

    public function init(): void {
        if (!$this->user->hasPermission('translate')) {
            return;
        }

        $this->initSettings();
        $this->addHookAfter("ProcessPageEdit::getSubmitActions", $this, "addDropdownOption");
        $this->addHookAfter("Pages::saved", $this, "hookTranslatePageSave");
        $this->addHookAfter("Pages::saved", $this, "hookLanguagePageSave");

        parent::init();
    }

    public function initSettings(): void {
        // Set (user-)settings
        $this->deepLApiKey = $this->get('deepLApiKey');
        $this->deepLGlossaryId = $this->get('deepLGlossaryId');
        $this->sourceLanguageName = $this->get('sourceLanguageName');
        $this->excludedTemplates = $this->get('excludedTemplates');
        $this->excludedFields = $this->get('excludedFields');
        $this->excludedLanguages = $this->get('excludedLanguages');
        $this->writemode = $this->get('writemode');
        $this->showSingleTargetLanguageButtons = !!$this->get('showSingleTargetLanguageButtons');
        $this->throttleSave = 5;

        $this->setLanguages();
    }

    private function initApi(): bool {
        if ($this->deepLApiKey) {
            try {
                $this->deepL = new \DeepL\DeepLClient($this->deepLApiKey);
            } catch (\DeepL\DeepLException $e) {
                $this->error($e->getMessage());

                return false;
            }

            $this->glossaryInstance = new TranslateGlossary($this);
            $this->glossary = $this->glossaryInstance->getGlossary();

            return true;
        }

        return false;
    }

    public function hookTranslatePageSave($event): void {
        /** @var Page $page */
        $page = $event->arguments('page');
        // We need the changed field names as a simple array
        $this->changedFields = array_values($event->arguments(1));

        // Only start translating if post variable is set
        if (!str_contains($this->input->post->text('_after_submit_action'), 'save_and_translate')) {
            return;
        }

        // Check if post variable has an appended single target language code
        if ($this->input->post->_after_submit_action !== 'save_and_translate') {
            // Selected target language is the last part of the post variable
            $singleTargetLanguage = str_replace('save_and_translate_', '', $this->input->post->_after_submit_action);

            // Filter all allowed target languages for the selected language name
            $this->targetLanguages = array_filter($this->targetLanguages, function ($targetLanguage) use ($singleTargetLanguage) {
                return $targetLanguage->name === $singleTargetLanguage;
            });
        }

        // Throttle translations (only triggers every after a set amount of time)
        if ($this->page->modified > (time() - $this->throttleSave)) {
            $this->error(__('Please wait some time before you try to translate again.'));

            return;
        }

        if (!$this->initApi()) {
            return;
        }

        if (!$this->checkForLanguageLocales()) {
            $this->error(__('One or more languages have no locale set. Please set the locale for all languages.'));;

            return;
        }

        // Let’s go!
        $this->processFields($page);
        $this->message($this->translatedFieldsCount.' '.__('fields translated.'));
    }

    public function hookLanguagePageSave($event) {
        /** @var Language $language */
        $language = $event->arguments('page');
        if ($language->template->name !== 'language') {
            return;
        }
        // We need the changed field names as a simple array
        $changedFields = array_values($event->arguments(1));

        if(!$this->initApi()) {
            return;
        }

        if(!$this->glossary) {
            return;
        }

        if (in_array('translate_glossary', $changedFields)) {
            $this->glossaryInstance->setGlossaryDictionary($language->translate_glossary, $this->sourceLanguage->translate_locale, $language->translate_locale);
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

        $label = "%s + ".__('Translate');

        // If single buttons are set, add one button for each target language
        if ($this->showSingleTargetLanguageButtons) {
            foreach ($this->targetLanguages as $targetLanguage) {
                $actions[] = [
                    'value' => 'save_and_translate_'.$targetLanguage->name,
                    'icon' => 'language',
                    'label' => $label.': '.$this->sourceLanguage->get('title|name').' &rarr; '.$targetLanguage->get('title|name'),
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
        $this->initApi();
        $this->initSettings();
        // Only process page if template is valid
        if (!in_array($page->template->name, array_merge($this->adminTemplates, $this->excludedTemplates))) {
            $this->processFields($page, false);
            echo "Process page {$page->title} ({$page->id})\n";
        }

        $selector = ($includeHidden) ? 'include=hidden' : '';

        // Iterate through all children and process them recursively
        foreach ($page->children($selector) as $item) {
            $this->translatePageTree($item, $includeHidden);
        }
    }

    public function translatePage(Page $page) {
        $this->initApi();
        $this->initSettings();
        $this->processFields($page, false);
    }

    private function setLanguages(): void {
        // Set source language
        $sourceLanguageName = $this->sourceLanguageName ?: 'default';
        foreach (wire('languages') as $language) {
            if ($language->name == $sourceLanguageName) {
                $this->sourceLanguage = $language;
                break;
            }
        }

        // Set target languages[]
        foreach (wire('languages') as $language) {
            // Ignore languages which are set as excluded or source in user settings
            if (in_array($language->name, $this->excludedLanguages) || $language->name == $this->sourceLanguage->name) {
                continue;
            }

            $this->targetLanguages[] = $language;
        }
    }

    private function translate(string $value, string $targetLanguageLocale): string {
        if (!$targetLanguageLocale) {
            return '';
        }

        // Ignore all fields which start with a Hanna Code tag
        if (str_starts_with($value, '[[')) {
            return $value;
        }

        $options = [
            'preserve_formatting' => true,
            'tag_handling' => 'html',
        ];

        if ($this->glossary !== null && $this->glossaryInstance->dictionaryExists($this->sourceLanguage->translate_locale, $targetLanguageLocale)) {
            $options['glossary'] = $this->glossary;
        }

        if (strtolower($targetLanguageLocale) === 'en') {
            $targetLanguageLocale = 'EN-GB';
        }

        $result = $this->deepL->translateText($value, $this->sourceLanguage->translate_locale, $targetLanguageLocale, $options);
        $resultText = $result->text;

        return $resultText;
    }

    private function processFields($page, $isPageWhichSaveWasHookedOn = true) {
        $page->of(false);
        $fields = $page->template->fields;

        if (get_class($page) === 'ProcessWire\RepeaterMatrixPage') {
            $fields = $page->matrix('fields');
        }

        foreach ($fields as $field) {
            $this->currentField = $field;

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

            $shortType = $this->getShortType($field->type);

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
        $value = $page->getLanguageValue($this->sourceLanguage, $fieldName);
        $countField = false;

        foreach ($this->targetLanguages as $targetLanguage) {
            // If field is empty or translation already exists and should not be overwritten, return
            if (!$value || ($page->getLanguageValue($targetLanguage, $fieldName) != '' && $this->writemode == 'empty')) {
                continue;
            }
            $result = $this->translate($value, $targetLanguage->translate_locale);
            $page->setLanguageValue($targetLanguage, $fieldName, $result);
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

        $fileFields = ['description']; //
        // Check if file field has custom template with additional fields
        if ($fieldTemplate = $field->getFieldsTemplate()) {
            foreach ($fieldTemplate->fields as $fileField) {
                // Check if field is multilanguage
                $shortType = $this->getShortType(get_class($fileField->type));
                if (in_array($shortType, $this->textFieldTypes)) {
                    $fileFields[] = $fileField->name;
                }
            }
        }

        // Iterate through entries in file field
        foreach ($field as $item) {
            // Iterate through fields of each entry (only description if no custom file template is created)
            /** @var Pageimage $fileField */
            foreach ($fileFields as $fileField) {
                $value = $item->$fileField;
                // Iterate through all target languages
                foreach ($this->targetLanguages as $targetLanguage) {
                    $targetLangValue = ($fileField === 'description') ? $item->$fileField($targetLanguage) : $item->$fileField->getLanguageValue($targetLanguage);

                    if (!$value || ($targetLangValue != '' && $this->writemode == 'empty')) {
                        continue;
                    }
                    $result = $this->translate($value, $targetLanguage->translate_locale);

                    if ($fileField === 'description') {
                        $item->$fileField($targetLanguage, $result);
                    } else {
                        $item->$fileField->setLanguageValue($targetLanguage, $result);
                    }

                    $countField = true;
                }
                $item->save();
                if ($countField) {
                    $this->translatedFieldsCount++;
                }
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
                $result = $this->translate($value, $targetLanguage->translate_locale);
                $page->$field->$targetFieldName = $result;
                $countField = true;
            }
            if ($countField) {
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
                    $value = $item->getLanguageValue($this->sourceLanguage);
                    $countField = false;

                    foreach ($this->targetLanguages as $targetLanguage) {
                        // If field is empty or translation already exists and should not be overwritten, return
                        if (!$value || ($item->getLanguageValue($targetLanguage) != '' && $this->writemode == 'empty')) {
                            continue;
                        }
                        $result = $this->translate($value, $targetLanguage->translate_locale);
                        $item->setLanguageValue($targetLanguage, $result);
                        $countField = true;
                    }

                    if ($countField) {
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

    private function processComboField(ComboLanguagesValue $comboField, string $comboFieldName, string $comboFieldsName, Page $page) {
        $value = $page->$comboFieldsName->$comboFieldName->getLanguageValue($this->sourceLanguage);
        $countField = false;

        foreach ($this->targetLanguages as $targetLanguage) {
            // If field is empty or translation already exists and should not be overwritten, return
            if (!$value || ($page->$comboFieldsName->$comboFieldName->getLanguageValue($targetLanguage) != '' && $this->writemode == 'empty')) {
                continue;
            }
            $result = $this->translate($value, $targetLanguage->translate_locale);
            $page->$comboFieldsName->$comboFieldName->setLanguageValue($targetLanguage, $result);
            $countField = true;
        }

        $page->save($comboFieldsName);
        if ($countField) {
            $this->translatedFieldsCount++;
        }
    }

    private function getShortType(string $fieldName): string {
        // e.g. Processwire/FieldtypePageTitleLanguage -> PageTitleLanguage
        $shortType = str_replace('ProcessWire\\', '', $fieldName);
        $shortType = str_replace('ProcessWire/', '', $shortType);
        $shortType = str_replace('Fieldtype', '', $shortType);

        return $shortType;
    }

    private function checkForLanguageLocales(): bool {
        $hasLocales = true;
        foreach (wire('languages') as $language) {
            if (!$language->translate_locale) {
                $hasLocales = false;

                break;
            }
        }
        return $hasLocales;
    }
}
