<?php namespace ProcessWire;

$excludedTemplatesOptions = [];
foreach (wire('templates') as $template) {
    if ($template->flags && $template->flags === Template::flagSystem) {
        continue;
    }
    $excludedTemplatesOptions[$template->name] = $template->get('label|name');
}

$config = [
	'overwriteExistingTranslation' => [
		'type' => 'checkbox',
		'label' => __('Overwrite existing translations'),
		'description' => __('If checked, all existing target language fields are overwritten on save. Otherwise, only empty fields are filled.'),
		'value' => false,
        'columnWidth' => 50
	],

    'excludedTemplates' => [
		'type' => 'asmSelect',
		'label' => __('Excluded Templates'),
		'description' => __('Pages with these templates will not display the save + translate option in the save dropdown'),
        'options' => $excludedTemplatesOptions,
		'value' => [],
        'columnWidth' => 50
	],

];