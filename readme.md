# ProcessTranslatePage – A Processwire Module for Effortless Page Translation

Welcome to ProcessTranslatePage! This module is designed to help you translate all text fields on a page in Processwire with ease.

As translations might take some time to proceed, PHP timeouts might occur on pages with a lot of fields and/or text. To bypass that, please refer to the section [Command line usage](#command-line-usage)

### Updating from 0.X to 1.0

With the release of version 1.0, ProcessTranslatePage now connects directly to DeepL for translations, eliminating the need for the Fluency module. After updating, enter your existing Fluency API key in the settings and ensure you add locales for each language on their respective edit pages.

### Installation

1. Download and install [ProcessTranslatePage](https://github.com/robertweiss/ProcessTranslatePage).
2. Configure your DeepL API credentials and adjust the module settings as needed.
3. Add locale information to your source and target language pages. You can find supported locales here: https://developers.deepl.com/docs/getting-started/supported-languages. If you wish to use glossaries, please add the relevant information to each language page. Note that the Free API plan supports only one multilanguage glossary.
4. Assign the ›translate‹ permission to your user role.
5. Open a page, click the arrow next to the save button, and select ›Save + Translate‹ to begin translating.

### Settings

- DeepL API Key
- DeepL Glossary ID (automatically set when using the glossary field in a language page)
- Source Language
- Exclude Templates
- Exclude Fields
- Exclude Target Languages
- Show Single Target Language Buttons
- Write Mode
  - Translate only if target field is empty
  - Translate only changed fields
  - Overwrite all target fields

Please note: The ›Changed fields‹ option currently supports only one level deep. If you modify any value inside a Repeater(-Matrix) or FieldsetPage field, the entire field will be translated.

### Field support

- PageTitleLanguage
- TextLanguage
- TextareaLanguage
- File (and image) descriptions
- Combo (ProField)
- RockPageBuilder (3rd party)
- All the mentioned fields inside Repeater, RepeaterMatrix, FieldsetPage, Functional and Table fields (ProFields)

### Command line usage

For translating multiple pages simultaneously and avoiding timeouts, you can use the included script `translate-pagetree.php` from the command line. Before running the script, please update the variables `$username`, `$home`, and `$includeHidden` to suit your requirements.

**Kindly note that this is a beta release.** Although it is successfully used in production for several of my clients, I recommend thorough testing before deploying it in your projects. If you encounter any bugs, please consider creating a GitHub issue to help me improve.
