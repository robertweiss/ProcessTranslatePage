<?php namespace ProcessWire;

interface TranslateProviderInterface {
    public function translate(string $text, string $sourceLocale, string $targetLocale): string;
}
