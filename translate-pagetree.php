<?php namespace ProcessWire;

// This script is build for usage in command line,
// as translations of multiple pages often lead to timeouts.

// Change according to your script location
include __DIR__ . '/../../../index.php';

// Only use script in command line to prevent timeouts!
if (!$config->cli) {
    exit('Script is only recommended for command line use');
}

// Set username to user with fluency-translate permissions
$username = 'USERNAME';
// Change to your preferred root page
$home = $pages->get(1);
// Should hidden pages be included?
$includeHidden = true;

$session->forceLogin($username);
$processTranslatePage = wire('modules')->get('ProcessTranslatePage');
$processTranslatePage->translatePageTree($home, $includeHidden);
