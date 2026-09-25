# Sample configurations for your LLM

Sample values for the *Configuration record* (see [Setup guide, step 2](../Readme.md#2-configure-the-llm-provider)).
EasyChat talks to any **OpenAI-compatible** endpoint. Enter the URL **without** a trailing `/v1`.

New to API keys? → [How to get an API key?](How-to-get-API-Keys.md)

## Mistral

Free plan available.

* Name:  `Mistral (mistral-tiny)`
* Model (of the LLM): `mistral-tiny`
* URL (of the API endpoint): `https://api.mistral.ai`
* API Key: `your-api-key-abc123xyz-...`
* System Message (Prompt): `You are a support chatbot ...`

## OpenAI / ChatGPT

Paid plan only.

* Name:  `Chatbot via OpenAI (gpt-4o-mini)`
* Model (of the LLM): `gpt-4o-mini`
* URL (of the API endpoint): `https://api.openai.com`
* API Key: `your-api-key-abc123xyz-...`
* System Message (Prompt): `You are a support chatbot ...`

## mittwald

Paid plan. A German hosting provider, which is helpful for data protection.

* Name:  `Support Chatbot via Mittwald (gpt-oss)`
* URL (of the API endpoint): `https://llm.aihosting.mittwald.de`
* Model (of the LLM): `gpt-oss-120b`
* API Key: `abc123xyz...`
* System Message (Prompt): `You are a support chatbot ...`

## Groq

Free plan available. Not tested by us, values taken from the provider's OpenAI-compatible API.

* Name:  `Groq (llama-3.3-70b-versatile)`
* Model (of the LLM): `llama-3.3-70b-versatile`
* URL (of the API endpoint): `https://api.groq.com/openai`
* API Key: `your-api-key-abc123xyz-...`
* System Message (Prompt): `You are a support chatbot ...`

## Ollama (self-hosted)

No API key and no costs, your data never leaves your server. Not tested by us.

* Name:  `Ollama (llama3.2)`
* Model (of the LLM): `llama3.2`
* URL (of the API endpoint): `http://localhost:11434` (from inside a DDEV container use `http://host.docker.internal:11434`)
* API Key: any placeholder, if the field is required
* System Message (Prompt): `You are a support chatbot ...`
