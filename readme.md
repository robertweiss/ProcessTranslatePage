# ProcessTranslatePage – A Processwire Module for Effortless Page Translation

Welcome to ProcessTranslatePage! This module translates all text fields on a page in Processwire via either DeepL or Google Cloud Translation.

As translations might take some time, PHP timeouts may occur on pages with many fields or a lot of text. To bypass this, refer to the [Command line usage](#command-line-usage) section.

### Installation

1. Download and install [ProcessTranslatePage](https://github.com/robertweiss/ProcessTranslatePage).
2. Choose your translation provider in the module settings and enter the required credentials (see below).
3. Add a locale to each language page (source and all targets). The module normalises the format automatically, so `DE`, `de`, `EN-GB`, `en_gb` and `En-Gb` are all accepted. Supported locale codes: [DeepL](https://developers.deepl.com/docs/getting-started/supported-languages) · [Google BCP-47](https://cloud.google.com/translate/docs/languages).
4. Assign the `translate` permission to your user role.
5. Open a page, click the arrow next to the save button, and select ›Save + Translate‹.

### Translation Providers

#### DeepL

Obtain an API key from https://www.deepl.com/pro/change-plan#developer and enter it in the module settings. Note that DeepL no longer offers a free API plan — new accounts receive a Developer API plan with a one-time contingent of 1,000,000 characters (see https://support.deepl.com/hc/en-us/articles/360021200939-DeepL-API-plans). The free plan is still supported if you already have one; it includes one multilingual glossary.

#### Google Cloud Translation

Google Cloud Translation v3 requires a service account. To set it up:

1. Open the [Google Cloud Console](https://console.cloud.google.com) and create a project (or select an existing one). Note the **Project ID** shown in the project selector at the top.
2. Enable the **Cloud Translation API** for your project (APIs & Services → Enable APIs).
3. Go to **IAM & Admin → Service Accounts** and create a new service account. Grant it the role **Cloud Translation API User** (`roles/cloudtranslate.user`).
4. Open the service account, go to the **Keys** tab, click **Add Key → Create new key**, and choose **JSON**. Download the key file.
5. Open the JSON file in a text editor, copy the entire contents, and paste it into the **Google Service Account JSON** field in the module settings.
6. Enter your **Project ID** in the corresponding field.

Note: Google Cloud Translation does not support glossaries in this module. The `translate_glossary` field on language pages is ignored when Google is the active provider.

### Settings

- **Translation Provider** — DeepL or Google Cloud Translation
- **DeepL API Key** — required when using DeepL
- **Active Glossary** (DeepL only) — select an existing glossary or let the module create one automatically from the glossary fields on each language page. The free plan is limited to one multilingual glossary.
- **Google Service Account JSON** — required when using Google Cloud Translation
- **Google Cloud Project ID** — required when using Google Cloud Translation
- **Source Language** — the language to translate from; defaults to the site's default language. Can also be set to the current user's language.
- **Write Mode**
  - *Translate only if target field is empty* — skips fields that already have a value
  - *Translate only changed fields* — translates only fields that were modified in this save (one level deep; entire Repeater/FieldsetPage fields are translated if any sub-value changes)
  - *Overwrite all target fields* — replaces all existing translations
- **Show Single Target Language Buttons** — adds one button per target language in the save dropdown instead of a single "translate all" button
- **Excluded Templates** — pages using these templates won't show the translate option
- **Excluded Fields** — fields to skip during translation
- **Excluded Languages** — target languages to skip

### Glossaries (DeepL only)

Each language page has a **Glossary** field. Enter one translation pair per line in the format `SourceWord==TargetWord`. Glossary entries are maintained on DeepL as a multilingual glossary and applied whenever a matching language pair is available.

When a language page is saved with updated glossary entries, the DeepL glossary is updated automatically. The glossary can be deleted from the module settings, which clears it from DeepL while keeping the entries in the language fields (a new glossary is created on the next translation).

The free DeepL plan supports only one multilingual glossary across all projects. If a glossary already exists on your account when the module tries to create one, a warning is shown and you can select the existing glossary manually.

### Field support

- PageTitleLanguage
- TextLanguage
- TextareaLanguage
- File and image descriptions (including custom file template fields)
- Combo (ProField)
- RockPageBuilder (3rd party)
- All of the above inside Repeater, RepeaterMatrix, FieldsetPage, Functional, and Table fields (ProFields)

### Batch Translation with ListerPro

The included `PageActionTranslatePage` module adds a batch translate action to ListerPro. Install it separately after ProcessTranslatePage and assign the `page-action-translate-page` permission to your user role. Then add the action to a ListerPro list to translate multiple pages at once.

**ListerPro** is a commercial ProcessWire module available at https://processwire.com/store/lister-pro/

### Command line usage

For large page trees where HTTP requests would time out, use the included `translate-pagetree.php` script from the command line. Set the `$username`, `$home`, and `$includeHidden` variables at the top of the file before running it.

This module is used in production across several client projects. Thorough testing before deploying to production is recommended. Bug reports are welcome via [GitHub issues](https://github.com/robertweiss/ProcessTranslatePage/issues).
