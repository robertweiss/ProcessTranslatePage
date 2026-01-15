<?php namespace ProcessWire;

use DeepL\DeepLException;
use DeepL\MultilingualGlossaryDictionaryEntries;
use DeepL\MultilingualGlossaryInfo;

class TranslateGlossary {
    private ?ProcessTranslatePage $translate;
    private ?MultilingualGlossaryInfo $glossary;
    private string $deepLGlossaryName;

    public function __construct(ProcessTranslatePage $translate) {
        $this->translate = $translate;
        $this->deepLGlossaryName = wire('config')->httpHost;
        $this->glossary = $this->setGlossary($this->translate->deepLGlossaryId);

        if (!$this->glossary) {
            $this->glossary = $this->createGlossary($this->translate->sourceLanguage);

            if ($this->glossary) {
                $deepLGlossaryId = $this->glossary->glossaryId;
                $data = wire('modules')->getConfig('ProcessTranslatePage');
                $data['deepLGlossaryId'] = $deepLGlossaryId;
                wire('modules')->saveConfig('ProcessTranslatePage', $data);
            }
        }
    }

    public function createGlossary(Language $sourceLanguage): ?MultilingualGlossaryInfo {
        $dictionaries = [];

        foreach (wire('languages') as $language) {
            if ($language->id !== $sourceLanguage->id) {
                $entryArr = self::convertGlossaryStringToArray($language->translate_glossary);
                if (empty($entryArr)) {
                    continue;
                }
                if (!$language->translate_locale) {
                    continue;
                }
                try {
                    $dictionaries[] = new MultilingualGlossaryDictionaryEntries(self::sanitizeLocale($sourceLanguage->translate_locale), self::sanitizeLocale($language->translate_locale), $entryArr);
                } catch (DeepLException $e) {
                    $this->translate->error($e->getMessage());
                }
            }
        }

        if (empty($dictionaries)) {
            return null;
        }

        try {
            $this->glossary = $this->translate->deepL->createMultilingualGlossary($this->deepLGlossaryName, $dictionaries);
        } catch (DeepLException $e) {
            $this->translate->error($e->getMessage());

            return null;
        }

        return $this->glossary;
    }

    public function setGlossary(?string $id = ''): ?MultilingualGlossaryInfo {
        if (!$id) {
            return null;
        }

        try {
            return $this->glossary = $this->translate->deepL->getMultilingualGlossary($id);
        } catch (\DeepL\GlossaryNotFoundException $e) {
            $this->translate->error($e->getMessage());

            return null;
        }
    }

    public function getGlossary(): ?MultilingualGlossaryInfo {
        return $this->glossary;
    }

    public function getGlossaries(): ?array {
        return $this->translate->deepL->listMultilingualGlossaries();
    }

    public function setGlossaryDictionary(string $dictionaryString, string $sourceLanguage, string $targetLanguage): void {
        $glossaryArray = $this->convertGlossaryStringToArray($dictionaryString);

        if (empty($glossaryArray)) {
            $this->translate->deepL->deleteMultilingualGlossaryDictionary($this->glossary, null, self::sanitizeLocale($sourceLanguage), self::sanitizeLocale($targetLanguage));
        } else {
            $dictionaryEntries = new MultilingualGlossaryDictionaryEntries(self::sanitizeLocale($sourceLanguage), self::sanitizeLocale($targetLanguage), $glossaryArray);
            $this->translate->deepL->replaceMultilingualGlossaryDictionary(
                $this->glossary, $dictionaryEntries
            );
        }
    }

    public static function sanitizeLocale(string $locale): string {
        if (strtolower($locale) === 'en-gb') {
            return 'EN';
        }

        return $locale;
    }

    public function dictionaryExists(string $sourceLanguage, string $targetLanguage) {
        try {
            $entries = $this->translate->deepL->getMultilingualGlossaryEntries($this->glossary, self::sanitizeLocale($sourceLanguage), self::sanitizeLocale($targetLanguage));

        } catch (\DeepL\GlossaryNotFoundException $e) {
            return false;
        }

        return true;
    }

    private static function convertGlossaryStringToArray(string $glossaryString = ''): array {
        if (!$glossaryString) {
            return [];
        }

        $glossaryArr = [];
        $rows = explode(PHP_EOL, $glossaryString);
        foreach ($rows as $row) {
            if (trim($row) === '') {
                continue;
            }

            // Split value at double equal sign (==)
            $entry = explode('==', $row);
            // Remove blanks around characters
            $entry = array_map(fn($val) => trim($val), $entry);

            if (isset($entry[0]) && isset($entry[1]) && $entry[0] && $entry[1]) {
                $glossaryArr[$entry[0]] = $entry[1];
            }
        }

        return $glossaryArr;
    }
}
