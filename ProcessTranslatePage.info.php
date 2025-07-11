<?php namespace ProcessWire;

$info = array(
	'title' => 'TranslatePage (via DeepL)',
	'summary' => 'Translates all textfields on a page via DeepL',
	'version' => 11,
	'author' => 'Robert Weiss',
	'icon' => 'language',
    'requires' => [
        'ProcessWire>=3.0.184'
    ],
	'href' => 'https://github.com/robertweiss/ProcessTranslatePage',
	'permission' => 'translate',
    'singular' => true,
    'autoload' => 'template=admin'
);
