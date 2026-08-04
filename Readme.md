# EasyChat Chatbot (TYPO3 Extension)

----

![TYPO Version 12](Documentation/Badges/TYPO3-12.png) ![TYPO Version 12](Documentation/Badges/TYPO3-13.png)

----

![EasyChat Logo](Documentation/Assets/EasyChat-Logo.png)

----


**EasyChat** is a lightweight open source chatbot for [TYPO3](https://typo3.org) websites without third party chat tools.

All you need is TYPO3 and an LLM endpoint.

EasyChat is **focused on privacy** and data protection, since all chat conversions are only stored in your TYPO3 database.

----

## 🚀 Features

* 🗨 **Chatbot frontend**
  * based on the open source chat framework [Deep Chat](https://deepchat.dev) (Supports: Vanilla JS, Vue, React, Angular etc.)


* 🤖 **LLM Endpoints** for
  * (self hosted) open source LLM's (e.g. gpt‑oss) and
  * standard LLMs (ChatGPT, Cloude Opus, Mistral etc.)
  * Chat memory (Chatbot can remember old questions)


* 🔒 **Data protection**:
  * Chat session **data is saved only within TYPO3** (on your own server)
  * Optional **privacy consent** before the chat starts
  * Automated **cleaning of user data** (scheduler task)

* ⛁ **Knoledge base** connector (to vector database)

---

## 🛠️ Setup guide (5 Steps)

### 1. Install the TYPO3 Extension

Install the **TYPO3 ChatBot Extension _EasyChat_** via [composer](https://getcomposer.org/doc/):

```console
composer require undkonsorten/easychat
```

After installation a **database compare** is necessary (via [Install Tool](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/Database/DatabaseUpgrade/Index.html#database-upgrade) or [TYPO3 Console](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/CommandControllers/ListCommands.html#console-command-extension-setup)) to create new tables.

### 2. Configure the LLM provider

Now you need to connect TYPO3 to your LLM's provider via API.
Create a new database record "Configuration" in TYPO3.

![Click to enlarge: Add new Configuration record](Documentation/Assets/Configuration-New-Database-Record.png)


Then fill out the fields of the *Configuration record*.

[![Click to enlarge: EasyChat backend Module](Documentation/Assets/Configuration_Thumb.png)](Documentation/Assets/Configuration.png)

#### Sample configurations for you LLM

* **Mistral** (Free plan available | [How to get an API key?](Documentation/How-to-get-API-Keys.md))
    * Name:  `Mistral (mistral-tiny)`
    * Model (of the LLM): `mistral-tiny`
    * URL (of the API endpoint): `https://api.mistral.ai`
    * API Key: `your-api-key-abc123xyz-...`
    * System Message (Promt): `You are a support chatbot ...`


* **OpenAI / ChatGPT** (Payed plan only | [How to get an API key?](Documentation/How-to-get-API-Keys.md))
    * Name:  `Chatbot via OpenAI (gpt-4o-mini)`
    * Model (of the LLM): `gpt-4o-mini`
    * URL (of the API endpoint): `https://api.openai.com`
    * API Key: `your-api-key-abc123xyz-...`
    * System Message (Promt): `You are a support chatbot ...`


* **mittwald** (Payed plan | [How to get an API key?](Documentation/How-to-get-API-Keys.md))
    * Name:  `Support Chatbot via Mittwald (gpt-oss)`
    * URL (of the API endpoint): `https://llm.aihosting.mittwald.de`
    * Model (of the LLM): `gpt-oss-120b`
    * API Key: `abc123xyz...`
    * System Message (Promt): `You are a support chatbot ...`

### 3. Setup the TYPO3 Reaction

A **TYPO3 Reaction** needs to be created.
The reaction serves as a connector (aka endpoint) between the chat frontend and TYPO3.

[![Click to enlarge: Reaction for EasyChat](Documentation/Assets/Reaction_Thumb.png)](Documentation/Assets/Reaction.png)

* Create a new reaction with the *Reaction Type* `Reaction for easychat`.
* Be sure to *copy the generated secret* before saving
* Chose one of the before created *EasyChat configuration record*

After sucessfully creating the reaction you will see the following interface.

[![Click to enlarge: Reaction List](Documentation/Assets/Reactions_Thumb.png)](Documentation/Assets/Reactions.png)

Now also *copy the reaction URL* (like `https://my-domain.com/typo3/reaction/afce5efb-861e-4e0e-8a8b-d159f194670d`).
You will need it in step 4.

### 4. Setup the Content Element

Last but not least you need to setup a *content element for the chatbot*.
* Be sure to have you *Reaction URL and secret* available.

* Open a TYPO3 page
* Add/create a new content element "Chatbot".
* Connect the content Element to the reaction.

> Hint: Use /typo3/reaction/XXXXXXXX-XXXXX instead of https://mydomain.dev/XXXXXXXX-XXXXX in order to be domain independent

[![Click to enlarge: Content Element EasyChat](Documentation/Assets/Content-Element_Thumb.png)](Documentation/Assets/Content-Element.png)

### ✨ YOU ARE DONE!!! 👊 CONGRATULATIONS 🎉

----


## Backend module for chat session logs

With the **EasyChat backend module** you can watch, review, delete and export chat sessions.

[![Click to enlarge: EasyChat backend Module](Documentation/Assets/Backend-Module_Thumb.png)](Documentation/Assets/Backend-Module.png)

### Exporting sessions as CSV

Use the *Export as CSV* panel on the session list (or *Export this session as CSV* on a single
session's detail view, to only export that one) to download conversations as CSV. Pick which
columns to include: *Session id*, *Created at*, *System prompt*, *Questions*, *Answers*.

When *Questions* and/or *Answers* are selected, each question/answer turn of a conversation
becomes its own CSV row (with the other selected columns repeated), instead of concatenating an
entire multi-turn conversation into one cell.

### How chat sessions are stored

Each browser session (the `easychat_session_id` cookie) maps to **exactly one row** in
`tx_easychat_domain_model_session`. The whole conversation — the system prompt plus every
question and answer — lives as one JSON blob in that row's `messages` column and is updated in
place after every turn; a new row is only ever created the very first time a given session is
seen.

*Known limitation:* the system prompt is only ever read from the *Configuration* record's
*System Message* field when a session is first created (see `ChatReaction::react()`). Editing
that field later has **no effect on sessions that are already running** — they keep using
whichever prompt was configured when they started. The new value only applies to sessions
created after the change.

----


## Extension Configuration

EasyChat has a small set of global options in the **Extension Configuration**
(*Admin Tools → Settings → Extension Configuration → `easychat`*, or in
`config/system/settings.php` under `EXTENSIONS.easychat`):

| Key | Default | Meaning |
|:----|:--------|:--------|
| `storagePid` | `1` | Page/folder UID where chat sessions (`tx_easychat_domain_model_session`) are **stored and read**. This applies both to the chat endpoint that saves conversations and to the backend module that lists them — they always use the same value. |
| `itemsPerPage` | `50` | Number of sessions per page in the backend module list. |

```php
// config/system/settings.php
'EXTENSIONS' => [
    'easychat' => [
        'storagePid' => '1',
        'itemsPerPage' => '50',
    ],
],
```

> **Note:** `storagePid` is set here, **not** via TypoScript. A
> `plugin.tx_easychat.persistence.storagePid` in TypoScript has **no effect**,
> because sessions are persisted from a TYPO3 *Reaction* (outside the Extbase
> plugin request), which reads this value directly from the extension
> configuration. We recommend pointing `storagePid` at a dedicated **sysfolder**
> rather than the root page.

----


## Automated cleaner task for deleting old chat session

For data protection we recommend to setup the 🗑 *cleaner task* in order to delete old chat sessions.

[![Click to enlarge: EasyChat backend Module](Documentation/Assets/Scheduler-Task_Thumb.png)](Documentation/Assets/Scheduler-Task.png)

Steps:
* Choose the task `Execute console commands (scheduler)`
* Schedulable Commend: `easychat:delete-sessions: Deletes sessions older than given date interval.`
* Set the scheduler interval
* Save !
* Then define the `keepDateInterval` in the [ISO 8601 durations format](https://en.wikipedia.org/wiki/ISO_8601#Durations).


| Duration             | ISO 8601 Format |
|:---------------------|:----------------|
| 1 Day                | P1D             |
| 2 Weeks              | P2W             |
| 3 Months             | P3M             |
| 1 Year               | P1Y             |
| 1 Year and 2 Months  | P1Y2M           |


----

## Knowledge base (RAG) via EXT:index

EasyChat can answer questions using your own site content instead of (or in addition to) the LLM's
general knowledge, by embedding your pages/files into a **Qdrant** vector store and retrieving relevant
chunks at chat time (`Symfony\AI\Agent\Bridge\SimilaritySearch\SimilaritySearch`, already wired into
`ChatReaction`).

Indexing itself is delegated to [`lochmueller/index`](https://github.com/lochmueller/index), a generic
TYPO3 content-crawling framework. EasyChat listens to its `IndexPageEvent`/`IndexFileEvent` (see
`Classes/Indexing/IndexEventListener.php`) and pushes the crawled content into the vector store(s) of any
matching *Configuration* record.

Setup:

1. Require the vector store bridge: `composer require symfony/ai-qdrant-store` (not installed by default,
   since not every EasyChat site uses a vector database).
2. Create a `tx_index_domain_model_configuration` record (EXT:index) on your site's root page — pick a
   *technology* (`Database` is fastest, `Frontend` renders real page markup), enable *content indexing*,
   and set the languages/levels to crawl. See [EXT:index's README](https://github.com/lochmueller/index)
   for the full field reference.
3. Create the two scheduler tasks EXT:index needs to actually run: `index:queue` (fills the queue) and
   `messenger:consume` fill `index` in the field **Argument** (processes it) — see EXT:index's README for details.
4. On your EasyChat *Configuration* record, set *Vector db* to `Qdrant`, fill in the connection fields
   (host, port, collection name, API key, embeddings model, and the embedding model's output *dimensions*
   — e.g. `1536` for `text-embedding-3-small`), and select the index configuration(s) from step 2 in the
   new *Index configurations* field.
   * By default the embeddings model is called on the **same API url/key as the chat LLM** above (just a
     different model id). If your embeddings model lives on a different endpoint/provider, set the
     optional *Embeddings API url* / *Embeddings API key* fields to override it.

Once a scheduler run has indexed some pages, ask the chatbot a question whose answer only exists in your
site content — the agent will call the similarity-search tool automatically when relevant.

*Known limitation:* re-indexing a page overwrites its previous vectors, but if a page's content shrinks
across runs (fewer chunks than before), the extra old chunks from the larger version are not cleaned up
automatically.

----


## Theming & Templates

### Chat Fronend: Deep Chat

EasyChat comes along with *Deep Chat* - an **open source chat web component** in the frontend.

[![Click to enlarge: Deep-Chat Styles](Documentation/Assets/DeepChat_Thumb.png)](Documentation/Assets/DeepChat.png)

For simplicity we integrated *Deep Chat* as a plain **Vanilla JS** web component, but [it can be used with many other frameworks](https://deepchat.dev/examples/frameworks) (e.g. React, Vue, Svele, Angular).
EasyChat is able to communicate with popular AI providers, but can also connect to your own servers - in our example with TYPO3.

DeepChat is an example implemention. Feel free to use another chatbot frontend.
The default dummy template is located at
* `/Resources/Private/Templates/ChatFrontend.html`.

### Styled version

If you want to use our *suggested default styles for ChatBot & Cookie Consent* you need to include the TypoScript templates in `/Configuration/Styling/`.

[![Click to enlarge: Include Styles via TypoScript](Documentation/Assets/Styling-TypoScript_Thumb.png)](Documentation/Assets/Styling-TypoScript.png)

### How to add your own styles 💐

You can either overlay the default template or the styled template, by setting your own template paths.

```typoscript
# overlay the easychat styled version
plugin.tx_easychat.view {
    partialRootPaths.10 = EXT:my-sitepackage/Resources/Private/_Default/Easychat/Partials/
    templateRootPaths.10 = EXT:my-sitepackage/Resources/Private/_Default/Easychat/Templates/
}
```

Be aware,
* there a many ways to inject styles to a web component like `<deep-chat>`
* keep also in mind the styles for the chatbot trigger button and the consent module

### How to Change the texts

Your edit some of the content directly in the Frontend.

You can ovverride texts used in the template via locallang.xml oder via TypoScript.

```typoscript
plugin.tx_easychat {
    _LOCAL_LANG {
        default.easychat_nagscreenMessage = Hast du Fragen zu unserem Angebot?
        default.easychat_dataProtectionConsentAcceptedMessage = Einwilligung erteilt
    }
}
```
---

## Credits

🙏 This TYPO3 Extension was build by the Berlin based digtal agency [undkonsorten](https://undkonsorten.com).
* Eike Starkmann (Product Owner & Inspirator, TYPO3 Development)
* Lars Hayer (Frontend, Theming)
* Thomas Alboth (Product Owner & Documentation)
* Jule Nott (UI Design)
* Felix Althaus & J. (Critical Thinking)

---

## Contact

Questions? Suggestions? Support needed? Feel free to 📧 [contact us](https://undkonsorten.com/kontakt).

---

## License

[GNU General Public License, version 2](http://www.gnu.org/licenses/gpl-2.0.html)


---

## Planned Featues

* ~~Website scraping/indexing via TYPO3 for the knowledge base (via vector database)~~ — done, see
  [Knowledge base (RAG) via EXT:index](#knowledge-base-rag-via-extindex)
