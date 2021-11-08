<?php namespace ProcessWire;

$sourceLanguageOptions = [];
if (wire('languages')) {
    foreach (wire('languages') as $language) {
        $sourceLanguageOptions[$language->name] = $language->get('title|name');
    }
}

$excludedTemplatesOptions = [];
if (wire('templates')) {
    foreach (wire('templates') as $template) {
        if ($template->flags && $template->flags === Template::flagSystem) {
            continue;
        }
        $label = $template->label ? $template->label . ' (' . $template->name . ')' : $template->name;
        $excludedTemplatesOptions[$template->name] = $label;
    }
}

$excludedFieldsOptions = [];
if (wire('fields')) {
    foreach (wire('fields') as $field) {
        if ($field->flags && $field->flags === Field::flagSystem) {
            continue;
        }
        $label = $field->label ? $field->label . ' (' . $field->name . ')' : $field->name;
        $excludedFieldsOptions[$field->name] = $label;
    }
}

$excludedLanguagesOptions = [];
if (wire('languages')) {
    foreach (wire('languages') as $language) {
        if ($language->name === 'default') {
            continue;
        }
        $excludedLanguagesOptions[$language->name] = $language->get('title|name');
    }
}

$config = [
    'sourceLanguage' => [
		'type' => 'select',
		'label' => __('Source Language'),
		'description' => __('The language which will be used to translate from. If no selection is made, the default language will be used'),
        'options' => $sourceLanguageOptions,
		'value' => [],
        'columnWidth' => 33
	],

	'overwriteExistingTranslation' => [
		'type' => 'checkbox',
		'label' => __('Overwrite existing translations'),
		'description' => __('If checked, all existing target language fields are overwritten on save. Otherwise, only empty fields are filled.'),
		'value' => false,
        'columnWidth' => 33
	],

    'showSingleTargetLanguageButtons' => [
		'type' => 'checkbox',
		'label' => __('Show single target language buttons'),
		'description' => __('If checked, the save dropdown will add one button for each allowed target language instead of one button for all languages combined.'),
		'value' => false,
        'columnWidth' => 34
	],

    'excludedTemplates' => [
		'type' => 'asmSelect',
		'label' => __('Excluded Templates'),
		'description' => __('Pages with these templates will not display the save + translate option in the save dropdown'),
        'options' => $excludedTemplatesOptions,
		'value' => [],
        'columnWidth' => 33
	],

    'excludedFields' => [
		'type' => 'asmSelect',
		'label' => __('Excluded Fields'),
		'description' => __('Fields that will be ignored when translating'),
        'options' => $excludedFieldsOptions,
		'value' => [],
        'columnWidth' => 33
	],

    'excludedLanguages' => [
		'type' => 'asmSelect',
		'label' => __('Excluded Languages'),
		'description' => __('Target languages that will be ignored when translating'),
        'options' => $excludedLanguagesOptions,
		'value' => [],
        'columnWidth' => 34
	],
];
