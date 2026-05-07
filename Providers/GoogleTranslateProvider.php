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

    public function translate(string $text, string $sourceLocale, string $targetLocale, bool $isHtml = false): string {
        if (trim($text) === '') {
            return '';
        }
        $request = (new TranslateTextRequest())
            ->setParent($this->parent)
            ->setContents([$text])
            ->setSourceLanguageCode(self::normalizeLocale($sourceLocale))
            ->setTargetLanguageCode(self::normalizeLocale($targetLocale));

        if ($isHtml) {
            $request->setMimeType('text/html');
        }

        try {
            $response = $this->client->translateText($request);
            $translations = $response->getTranslations();
            if (count($translations) === 0) {
                return '';
            }
            $result = $translations[0]->getTranslatedText();
            // Google always returns HTML-encoded output; decode for plain-text fields
            if (!$isHtml) {
                $result = html_entity_decode($result, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            return $result;
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
