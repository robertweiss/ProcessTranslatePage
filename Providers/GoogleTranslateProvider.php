<?php namespace ProcessWire;

use Google\Cloud\Translate\V3\Client\TranslationServiceClient;
use Google\Cloud\Translate\V3\TranslateTextRequest;
use Google\ApiCore\ApiException;

class GoogleTranslateProvider implements TranslateProviderInterface {
    private TranslationServiceClient $client;
    private string $parent;

    public function __construct(
        array $credentials,
        private string $projectId,
        private ProcessTranslatePage $module
    ) {
        $this->client = new TranslationServiceClient(['credentials' => $credentials]);
        $this->parent = 'projects/' . $projectId . '/locations/global';
    }

    public function translate(string $text, string $sourceLocale, string $targetLocale): string {
        if (trim($text) === '') {
            return '';
        }

        $request = (new TranslateTextRequest())
            ->setParent($this->parent)
            ->setContents([$text])
            ->setMimeType('text/html')
            ->setSourceLanguageCode(self::normalizeLocale($sourceLocale))
            ->setTargetLanguageCode(self::normalizeLocale($targetLocale));

        try {
            $response = $this->client->translateText($request);
            $translations = $response->getTranslations();
            if (count($translations) === 0) {
                return '';
            }
            return $translations[0]->getTranslatedText();
        } catch (ApiException $e) {
            $this->module->error($e->getMessage());
            return '';
        }
    }

    private static function normalizeLocale(string $locale): string {
        $parts = explode('-', str_replace('_', '-', $locale), 2);
        $normalized = strtolower($parts[0]);
        if (isset($parts[1])) {
            $normalized .= '-' . strtoupper($parts[1]);
        }
        return $normalized;
    }
}
