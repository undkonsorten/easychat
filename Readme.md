# EasyChat Chatbot (TYPO3 Extension)

----

![TYPO Version 12](Documentation/Badges/TYPO3-12.png) ![TYPO Version 12](Documentation/Badges/TYPO3-13.png)

----

<span style="float:right;margin-inline-start: 1em">![EasyChat Logo (Icon)](Documentation/Assets/EasyChat-Icon.png)</span>
**EasyChat** is a lightweight open source chatbot für [TYPO3](https://typo3.org) websites without third party chat tools.

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

[![Click to enlarge: Content Element EasyChat](Documentation/Assets/Content-Element_Thumb.png)]((Documentation/Assets/Content-Element.png))

### ✨ YOU ARE DONE!!! 👊 CONGRATULATIONS 🎉

----


## Backend module for chat session logs

With the **EasyChat backend module** you can watch, review and delete single chat session.

[![Click to enlarge: EasyChat backend Module](Documentation/Assets/Backend-Module_Thumb.png)](Documentation/Assets/Backend-Module.png)

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


## Theming & Templates

You can add your own beautiful 💐 templates, js and css files with the following TypoScript configuration:

```typoscript
plugin.tx_easychat.view {
    partialRootPaths.10 = EXT:my-sitepackage/Resources/Private/_Default/Easychat/Partials/
    templateRootPaths.10 = EXT:my-sitepackage/Resources/Private/_Default/Easychat/Templates/
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

## To Do

* Documentation
  * Theming DeepChat

---

## Planned Featues

* Website scraping/indexing via TYPO3 for the knowledge base (via vector database)
* Impoved connector to vector database as a knowledge base for the chatbot
