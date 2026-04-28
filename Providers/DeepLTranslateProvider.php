<?php namespace ProcessWire;

class DeepLTranslateProvider implements TranslateProviderInterface {
    private \DeepL\DeepLClient $client;
    private ?TranslateGlossary $glossaryManager = null;

    public function __construct(
        private string $apiKey,
        private ?string $glossaryId,
        private bool $isFreeAccount,
        private Language $sourceLanguage,
        private ProcessTranslatePage $module
    ) {
        $this->client = new \DeepL\DeepLClient($apiKey);
        $this->glossaryManager = new TranslateGlossary($this->client, $module, $glossaryId, $sourceLanguage, $isFreeAccount);
    }

    public function getClient(): \DeepL\DeepLClient {
        return $this->client;
    }

    public function getGlossaryManager(): ?TranslateGlossary {
        return $this->glossaryManager;
    }

    public function translate(string $text, string $sourceLocale, string $targetLocale): string {
        $sourceLocale = self::normalizeLocale($sourceLocale);
        $targetLocale = self::normalizeLocale($targetLocale);

        $options = [
            'preserve_formatting' => true,
            'tag_handling' => 'html',
        ];

        if ($this->glossaryManager !== null && $this->glossaryManager->getGlossary() !== null && $this->glossaryManager->dictionaryExists($sourceLocale, $targetLocale)) {
            $options['glossary'] = $this->glossaryManager->getGlossary();
        }

        try {
            $result = $this->client->translateText($text, $sourceLocale, $targetLocale, $options);
        } catch (\DeepL\DeepLException $e) {
            $this->module->error($e->getMessage());
            return '';
        }

        return $result->text;
    }

    private static function normalizeLocale(string $locale): string {
        $locale = strtoupper(str_replace('_', '-', $locale));
        // DeepL requires EN-GB rather than bare EN
        if ($locale === 'EN') {
            return 'EN-GB';
        }
        return $locale;
    }

    public function updateGlossaryDictionary(string $glossaryString, string $sourceLocale, string $targetLocale): void {
        $this->glossaryManager?->setGlossaryDictionary($glossaryString, $sourceLocale, $targetLocale);
    }

    public function deleteGlossary(): void {
        $this->glossaryManager?->deleteGlossary();
    }
}
