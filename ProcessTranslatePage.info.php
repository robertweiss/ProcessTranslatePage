<?php namespace ProcessWire;

$info = array(
	'title' => 'TranslatePage (via Fluency)',
	'summary' => 'Translates all textfields on a page (including repeater(-matrix), file descriptions and functional fields)',
	'version' => 2,
	'author' => 'Robert Weiss',
	'icon' => 'language',
	'requires' => 'Fluency',
	'href' => 'https://github.com/robertweiss/ProcessTranslatePage',
	'permission' => 'fluency-translate',
    'singular' => true,
    'autoload' => true
);
