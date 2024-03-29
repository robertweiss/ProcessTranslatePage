<?php namespace ProcessWire;

$info = array(
	'title' => 'TranslatePage (via Fluency)',
	'summary' => 'Translates all textfields on a page via Fluency',
	'version' => 8,
	'author' => 'Robert Weiss',
	'icon' => 'language',
    'requires' => [
        'Fluency',
        'ProcessWire>=3.0.184'
    ],
	'href' => 'https://github.com/robertweiss/ProcessTranslatePage',
	'permission' => 'fluency-translate',
    'singular' => true,
    'autoload' => 'template=admin'
);
