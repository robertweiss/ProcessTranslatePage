<?php namespace ProcessWire;

use DeepL\DeepLException;
use DeepL\MultilingualGlossaryDictionaryEntries;
use DeepL\MultilingualGlossaryInfo;

class TranslateGlossary {
    private ?\DeepL\DeepLClient $client;
    private ?ProcessTranslatePage $module;
    private ?MultilingualGlossaryInfo $glossary;
    private string $deepLGlossaryName;

    public function __construct(
        \DeepL\DeepLClient $client,
        ProcessTranslatePage $module,
        ?string $glossaryId,
        Language $sourceLanguage,
        bool $isFreeAccount = false
    ) {
        $this->client = $client;
        $this->module = $module;
        $this->deepLGlossaryName = wire('config')->httpHost;
        $this->glossary = $this->setGlossary($glossaryId);

        if (!$this->glossary) {
            if ($isFreeAccount) {
                $existing = $this->getGlossaries();
                if (!empty($existing)) {
                    $this->module->warning($this->module->_('DeepL free plan: an existing glossary was found. Select it in module settings to use it for translations.'));
                } else {
                    $this->glossary = $this->createGlossary($sourceLanguage);
                    if ($this->glossary) {
                        $this->saveGlossaryId($this->glossary->glossaryId);
                    }
                }
            } else {
                $this->glossary = $this->createGlossary($sourceLanguage);
                if ($this->glossary) {
                    $this->saveGlossaryId($this->glossary->glossaryId);
                }
            }
        }
    }

    public function createGlossary(Language $sourceLanguage): ?MultilingualGlossaryInfo {
        $dictionaries = [];

        foreach (wire('languages') as $language) {
            if ($language->id !== $sourceLanguage->id) {
                $entryArr = self::convertGlossaryStringToArray($language->translate_glossary ?? '');
                if (empty($entryArr)) {
                    continue;
                }
                if (!$language->translate_locale) {
                    continue;
                }
                try {
                    $dictionaries[] = new MultilingualGlossaryDictionaryEntries(self::sanitizeLocale($sourceLanguage->translate_locale), self::sanitizeLocale($language->translate_locale), $entryArr);
                } catch (DeepLException $e) {
                    $this->module->error($e->getMessage());
                }
            }
        }

        if (empty($dictionaries)) {
            return null;
        }

        try {
            $this->glossary = $this->client->createMultilingualGlossary($this->deepLGlossaryName, $dictionaries);
        } catch (DeepLException $e) {
            $this->module->error($e->getMessage());

            return null;
        }

        return $this->glossary;
    }

    public function setGlossary(?string $id = ''): ?MultilingualGlossaryInfo {
        if (!$id) {
            return null;
        }

        try {
            return $this->glossary = $this->client->getMultilingualGlossary($id);
        } catch (\DeepL\GlossaryNotFoundException) {
            $this->module->warning($this->module->_('Stored DeepL glossary not found, it may have been deleted. A new one will be created if possible.'));
            $this->saveGlossaryId(null);

            return null;
        } catch (DeepLException $e) {
            $this->module->error($e->getMessage());

            return null;
        }
    }

    public function getGlossary(): ?MultilingualGlossaryInfo {
        return $this->glossary;
    }

    public function getGlossaries(): ?array {
        try {
            return $this->client->listMultilingualGlossaries();
        } catch (DeepLException $e) {
            $this->module->error($e->getMessage());

            return null;
        }
    }

    public function deleteGlossary(): void {
        if (!$this->glossary) {
            return;
        }
        try {
            $this->client->deleteMultilingualGlossary($this->glossary);
        } catch (DeepLException $e) {
            $this->module->error($e->getMessage());
            return;
        }
        $this->saveGlossaryId(null);
        $this->glossary = null;
    }

    public function setGlossaryDictionary(string $dictionaryString, string $sourceLanguage, string $targetLanguage): void {
        if (!$this->glossary) {
            return;
        }

        $glossaryArray = $this->convertGlossaryStringToArray($dictionaryString);

        try {
            if (empty($glossaryArray)) {
                $this->client->deleteMultilingualGlossaryDictionary($this->glossary, null, self::sanitizeLocale($sourceLanguage), self::sanitizeLocale($targetLanguage));
            } else {
                $dictionaryEntries = new MultilingualGlossaryDictionaryEntries(self::sanitizeLocale($sourceLanguage), self::sanitizeLocale($targetLanguage), $glossaryArray);
                $this->client->replaceMultilingualGlossaryDictionary($this->glossary, $dictionaryEntries);
            }
        } catch (DeepLException $e) {
            $this->module->error($e->getMessage());
        }
    }

    public static function sanitizeLocale(string $locale): string {
        $locale = strtoupper(str_replace('_', '-', $locale));
        // DeepL glossary API uses bare EN for all English regional variants
        if (str_starts_with($locale, 'EN-')) {
            return 'EN';
        }
        return $locale;
    }

    public function dictionaryExists(string $sourceLanguage, string $targetLanguage): bool {
        if (!$this->glossary) {
            return false;
        }

        try {
            $this->client->getMultilingualGlossaryEntries($this->glossary, self::sanitizeLocale($sourceLanguage), self::sanitizeLocale($targetLanguage));
        } catch (DeepLException) {
            return false;
        }

        return true;
    }

    private function saveGlossaryId(?string $id): void {
        $data = wire('modules')->getConfig('ProcessTranslatePage');
        $data['deepLGlossaryId'] = $id;
        wire('modules')->saveConfig('ProcessTranslatePage', $data);
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

            $entry = explode('==', $row);
            $entry = array_map(fn($val) => trim($val), $entry);

            if (isset($entry[0]) && isset($entry[1]) && $entry[0] && $entry[1]) {
                $glossaryArr[$entry[0]] = $entry[1];
            }
        }

        return $glossaryArr;
    }
}
