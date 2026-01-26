# TYPO3 Chatbot: EasyChat


**EasyChat** is a lightweight open source chatbot für TYPO3 websites without third party chat tools.

EasyChat is focused on privacy and data protection, since all chat conversions are only stored in your TYPO3 database.


## Features


* Chatbot frontend - based on the open source chat framework [Deep Chat](https://deepchat.dev) (Supports: Vanilla JS, Vue, React, Angular etc.)
* Endpoints for self hosted open source LLM's (gpt‑oss, ) and the standard LLMs (ChatGPT, Cloude Opus, Mistral etc.)
* Chat memory for the LLM (chatbot does not forget old questions)
* Chat history/log for each user session is saved in TYPO3 (with an own backend module)
* User data privacy consent before the chat starts Chat/Logging (optional)
* Connector to a vector database as a knowledge base for the chatbot



## Setup

### Install the Extension

Install the EasyChat chatbot TYPO3 Extension via Composer:

```console
composer require undkonsorten/easychat
```

After installation a database compare is necessary to create new tables.

### Setup the LLM Provider

Now you need to connect TYPO3 to your LLM's provider via API. Create a new record "LLM Provider" in TYPO3.

Fill out the fields of the *LLM Provider record*. Here Examples for

* Gemini (Google)
    * Name:  `Gemini Support Chatbot`
    * URL: `https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=YOUR_API_KEY"`
    * Model: `gemini-1.5-flash`
    * API Key: [generate here](https://aistudio.google.com/api-keys)
    * System Message (Promt): `You are a support chatbot for the website www.example.com`

* mittwald
    * Name:  `Support Chatbot via Mittwald (gpt-oss)`
    * URL: `https://llm.aihosting.mittwald.de`
    * Model: `gpt-oss-120b`
    * API Key: `abc123xyz...`
    * System Message (Promt): `You are a support chatbot for the website www.example.com. Be friendly. Answer short.`

```console
curl "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=YOUR_API_KEY" \
-H 'Content-Type: application/json' \
-X POST \
-d '{
  "contents": [{
    "parts": [{"text": "Explain how an API works in one sentence."}]
  }]
}'
```

Currently (Jan 2026) you can get a free plan API testing here:
* [Mistral](https://console.mistral.ai/)
* [Google AI Studio](https://aistudio.google.com/)
* [HugginFace]
* [Grog](https://console.groq.com/)
* Cohere

OpenAi (ChatGpt) and Antrophic (Claude) offen only payed plans.

There are also webhosting providers in Germany with AI API endpoints. We are testing [mittwald](https://www.mittwald.de/mstudio/ai-hosting) at the moment (Jan 2026).

Usually

## Known Problems

### TYPO3 12 Compatibility

If you went to install EasyChat with TYPO 12.4,
you need to remove the package `typo3/cms-install` due to composer conflicts.





https://auth.openai.com/log-in


3. Datenatz LLM Connector Anlegen (mit API-Key)

4.a Plugin anlegen (Einbindung auf einer Seite) mit Promt etc oder

4.b Global einbinden


## Theming DeepChat


## Logging



## Data Protection


*EasyChat*





### Planned Featues

* website scraping for the knowledge base
* connector to Vector database (Chroma) as a knowledge base for the chatbot
