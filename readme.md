# ProcessTranslatePage – A Processwire module to translate all page fields via Fluency

ProcessTranslatePage is an extension for the Processwire module [Fluency by SkyLundy](https://github.com/SkyLundy/Fluency-Translation) so it can translate all text fields on a page at once. 

As translations might take some time to proceed, PHP timeouts might occur on pages with a lot of fields and/or text. To bypass that, see the section [Command line usage](#command-line-usage)

### Installation
1. Download and install [Fluency-Translation](https://github.com/SkyLundy/Fluency-Translation)
2. Configure the DeepL-API credentials and language settings
3. Download and install [ProcessTranslatePage](https://github.com/robertweiss/ProcessTranslatePage)
4. Configure the module settings if needed
5. Add the permission ›fluency-translate‹ to your user role
6. Open a page, click on the arrow next to the save-button, choose ›Save + Translate‹

### Settings
- Source Language
- Exclude Templates
- Exclude Fields
- Exclude Target Languages
- Show Single Target Language Buttons
- Write Mode
  - Translate only if target field is empty
  - Translate only changed fields
  - Overwrite all target fields

Caution: the »Changed fields«-option support is currently only one level deep. If you change any value inside a Repeater(-Matrix) or FieldsetPage field, the complete field will be translated.

### Field support
- PageTitleLanguage
- TextLanguage
- TextareaLanguage
- File (and image) descriptions
- All the mentioned fields inside Repeater, RepeaterMatrix, FieldsetPage, Functional and Table fields

### Command line usage
If you want to translate more than one page at time, you can execute the included script ```translate-pagetree.php``` in the command line to prevent timeouts. Before executing, change the variables ```$username```, ```$home``` and ```$includeHidden``` in the script according to your needs.

**Please note, this is an alpha release.** Please use in production after thorough testing for your own project and create Github issues for bugs found if possible.
