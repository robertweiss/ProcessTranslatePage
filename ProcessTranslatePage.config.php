<?php namespace ProcessWire;

$excludedTemplatesOptions = [];
if (wire('templates')) {
	foreach (wire('templates') as $template) {
		if ($template->flags && $template->flags === Template::flagSystem) {
			continue;
		}
		$excludedTemplatesOptions[$template->name] = $template->get('label|name');
	}
}

$sourceLanguageOptions = [];
if (wire('languages')) {
	foreach (wire('languages') as $language) {
		$sourceLanguageOptions[$language->id] = $language->name;
	}
}

////

$config = [
	'sourceLanguagePage' => [
		'type' => 'select',
		'label' => __('Source language'),
		'description' => __('The language which will be used to translate from.'),
		'options' => $sourceLanguageOptions,
		'value' => false,
		'columnWidth' => 33
	],

	'excludedTemplates' => [
		'type' => 'asmSelect',
		'label' => __('Excluded Templates'),
		'description' => __('Pages with these templates will not display the save + translate option in the save dropdown'),
		'options' => $excludedTemplatesOptions,
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

];
