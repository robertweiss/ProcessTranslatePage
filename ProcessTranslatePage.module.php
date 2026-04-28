<?php namespace ProcessWire;

require(__DIR__.'/vendor/autoload.php');
require(__DIR__.'/TranslateGlossary.php');
require(__DIR__.'/Providers/TranslateProviderInterface.php');
require(__DIR__.'/Providers/DeepLTranslateProvider.php');
require(__DIR__.'/Providers/GoogleTranslateProvider.php');

/**
 * Translate all textfields on a page via DeepL or Google Cloud Translation
 *
 */
class ProcessTranslatePage extends Process implements Module {
    private ?TranslateProviderInterface $provider = null;
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
            $field->description = $this->_('Used for translations. DeepL values: [https://developers.deepl.com/docs/getting-started/supported-languages](https://developers.deepl.com/docs/getting-started/supported-languages). Google uses BCP-47 codes (e.g. de, en-GB, fr).');
            $field->columnWidth = 50;
            $field->save();
        }

        if (!$fields->get('translate_glossary')) {
            $field = new Field;
            $field->type = $this->modules->get("FieldtypeTextArea");
            $field->name = "translate_glossary";
            $field->label = $this->_('Glossary');
            $field->description = $this->_('Custom DeepL Glossary. One translation pair per line. Pairs need to be divided by two equal signs (==) in order to be recognized.');
            $field->notes = $this->_('Glossary is only used in target languages (Source language word==Target language word). Glossary is only supported by the DeepL provider.');
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
        $this->addHookAfter("Modules::saveConfig", $this, "hookModuleConfigSave");

        if (!$this->user->hasPermission('translate')) {
            return;
        }

        $this->initSettings();
        $this->addHookAfter("ProcessPageEdit::getSubmitActions", $this, "addDropdownOption");
        $this->addHookAfter("Pages::saved", $this, "hookTranslatePageSave");
        $this->addHookAfter("Pages::saved", $this, "hookLanguagePageSave");

        parent::init();
    }

    public function hookModuleConfigSave($event): void {
        if ($event->arguments(0) !== 'ProcessTranslatePage') {
            return;
        }

        $data = wire('modules')->getConfig('ProcessTranslatePage');

        // DeepL: delete glossary if requested
        if ($this->input->post->bool('deepLGlossaryDelete')) {
            $apiKey = $data['deepLApiKey'] ?? '';
            $glossaryId = $data['deepLGlossaryId'] ?? '';
            if ($apiKey && $glossaryId) {
                try {
                    $client = new \DeepL\DeepLClient($apiKey);
                    $client->deleteMultilingualGlossary($glossaryId);
                    $data['deepLGlossaryId'] = null;
                    wire('modules')->saveConfig('ProcessTranslatePage', $data);
                    $this->message($this->_('DeepL glossary deleted.'));
                } catch (\DeepL\DeepLException $e) {
                    $this->error($e->getMessage());
                }
            }
        }

        // Google: validate credentials against the API
        if (($data['translationProvider'] ?? '') === 'google') {
            $credentialsJson = $data['googleCredentialsJson'] ?? '';
            $projectId = $data['googleProjectId'] ?? '';

            if (!$credentialsJson || !$projectId) {
                return;
            }

            $credentials = json_decode($credentialsJson, true);
            if (!is_array($credentials)) {
                $this->error($this->_('Google Translate: credentials JSON is invalid.'));
                return;
            }

            try {
                $client = new \Google\Cloud\Translate\V3\Client\TranslationServiceClient(['credentials' => $credentials]);
                $request = (new \Google\Cloud\Translate\V3\GetSupportedLanguagesRequest())
                    ->setParent('projects/' . $projectId . '/locations/global');
                $client->getSupportedLanguages($request);
                $this->message($this->_('Google Cloud Translation credentials verified successfully.'));
            } catch (\Google\ApiCore\ApiException $e) {
                $this->error($this->_('Google Translate: ') . $e->getMessage());
            } catch (\Exception $e) {
                $this->error($this->_('Google Translate: ') . $e->getMessage());
            }
        }
    }

    public function initSettings(): void {
        $this->sourceLanguageName = $this->get('sourceLanguageName');
        $this->excludedTemplates = $this->get('excludedTemplates') ?: [];
        $this->excludedFields = $this->get('excludedFields') ?: [];
        $this->excludedLanguages = $this->get('excludedLanguages') ?: [];
        $this->writemode = $this->get('writemode') ?: 'all';
        $this->showSingleTargetLanguageButtons = !!$this->get('showSingleTargetLanguageButtons');
        $this->throttleSave = 5;

        $this->setLanguages();
    }

    private function initApi(): bool {
        $translationProvider = $this->get('translationProvider') ?: 'deepl';

        if ($translationProvider === 'google') {
            $credentialsJson = $this->get('googleCredentialsJson') ?: '';
            $projectId = $this->get('googleProjectId') ?: '';

            if (!$credentialsJson || !$projectId) {
                $this->error($this->_('Google Translate: credentials JSON and project ID are required.'));
                return false;
            }

            $credentials = json_decode($credentialsJson, true);
            if (!is_array($credentials)) {
                $this->error($this->_('Google Translate: credentials JSON is invalid.'));
                return false;
            }

            try {
                $this->provider = new GoogleTranslateProvider($credentials, $projectId, $this);
            } catch (\Exception $e) {
                $this->error($e->getMessage());
                return false;
            }

            return true;
        }

        // DeepL (default)
        $apiKey = $this->get('deepLApiKey') ?: '';
        if (!$apiKey) {
            return false;
        }

        try {
            $isFreeAccount = \DeepL\Translator::isAuthKeyFreeAccount($apiKey);
            $glossaryId = $this->get('deepLGlossaryId') ?: null;
            $this->provider = new DeepLTranslateProvider($apiKey, $glossaryId, $isFreeAccount, $this->sourceLanguage, $this);
        } catch (\DeepL\DeepLException $e) {
            $this->error($e->getMessage());
            return false;
        }

        return true;
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
            $this->error(__('One or more languages have no locale set. Please set the locale for all languages.'));

            return;
        }

        // Let's go!
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

        if (!$this->initApi()) {
            return;
        }

        if (!($this->provider instanceof DeepLTranslateProvider)) {
            return;
        }

        if ($this->provider->getGlossaryManager()?->getGlossary() === null) {
            return;
        }

        if (in_array('translate_glossary', $changedFields)) {
            $this->provider->updateGlossaryDictionary($language->translate_glossary, $this->sourceLanguage->translate_locale, $language->translate_locale);
        }
    }

    public function addDropdownOption($event) {
        /** @var Page $page */
        $page = $this->pages->get($this->input->get->id);

        // Don't show option if page is not found or in excluded/admin templates
        if (!$page->id) return;
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

    public function ___getSourceLanguage(): Language {
        $sourceLanguageName = $this->sourceLanguageName ?: 'default';
        if ($sourceLanguageName === 'current_user_language') {
            return wire('user')->language;
        }
        foreach (wire('languages') as $language) {
            if ($language->name == $sourceLanguageName) {
                return $language;
            }
        }
        return wire('languages')->get('default');
    }

    public function ___getTargetLanguages(): array {
        $targetLanguages = [];
        foreach (wire('languages') as $language) {
            if (in_array($language->name, $this->excludedLanguages) || $language->name == $this->sourceLanguage->name) {
                continue;
            }
            $targetLanguages[] = $language;
        }
        return $targetLanguages;
    }

    private function setLanguages(): void {
        $this->sourceLanguage = $this->getSourceLanguage();
        $this->targetLanguages = $this->getTargetLanguages();
    }

    private function translate(string $value, string $targetLanguageLocale): string {
        if (!$targetLanguageLocale) {
            return '';
        }

        // Ignore all fields which start with a Hanna Code tag
        if (str_starts_with($value, '[[')) {
            return $value;
        }

        return $this->provider->translate($value, $this->sourceLanguage->translate_locale, $targetLanguageLocale);
    }

    private function processFields($page, $isPageWhichSaveWasHookedOn = true) {
        if ($page === null) return;
        $page->of(false);
        $fields = $page->template->fields;

        if (get_class($page) === 'ProcessWire\RepeaterMatrixPage') {
            $fields = $page->matrix('fields');
            if ($fields === null) return;
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
        if ($field === null || !$field->count()) {
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
                    if (!$value) continue;
                    $targetLangValue = ($fileField === 'description') ? $item->$fileField($targetLanguage) : $item->$fileField->getLanguageValue($targetLanguage);

                    if ($targetLangValue != '' && $this->writemode == 'empty') {
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
        if ($page->$field === null) return;
        foreach ($page->$field as $item) {
            $this->processFields($item, false);
        }
    }

    private function processFieldsetPage(Field $field, Page $page) {
        $this->processFields($page->$field, false);
    }

    private function processFunctionalField(Field $field, Page $page) {
        if ($page->$field === null) return;
        foreach ($page->$field as $name => $value) {
            // Ignore fallback values (starting with a dot)
            if (strpos($name, '.') === 0) {
                continue;
            }
            $countField = false;

            foreach ($this->targetLanguages as $targetLanguage) {
                $targetFieldName = $name.'.'.$targetLanguage->id;
                // If field is empty or translation already exists and should not be overwritten, continue
                if (!$value || ($page->$field->$targetFieldName != '' && $this->writemode == 'empty')) {
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
        if ($page->$field === null) return;

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
        if ($page->$field === null) return;
        foreach ($page->$field as $comboFieldName => $comboField) {
            if (!($comboField instanceof ComboLanguagesValue)) {
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
