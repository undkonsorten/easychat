# How to get an API key?

## 🔑 What is an API key?

In order to use an LLM you need an API key by one of the major players or an independent AI provider.

The API key allows you to identify yourself in order to use the API endpoint.

## 💰 Free vs Payed

🎁 **Free:** Currently (Jan 2026) you can get free for API keys testing via:

* [Mistral](https://console.mistral.ai/)
* Google/Gemini [via Google AI Studio](https://aistudio.google.com/)
* [HugginFace]
* [Grog](https://console.groq.com/)
* Cohere

💳 **Payed:** Currently you need a (pre)payed plan* for:
* OpenAi/ChatGpt ([platform.openai.com](https://platform.openai.com/settings/organization/api-keys))
* Antrophic / Claude
* Mittwald ([AI Hosting](https://www.mittwald.de/mstudio/ai-hosting))

## 🔒 Data protection

It might be tempting to use one of the big payers.
But when you want to be on the save side with your user data and your data protection officer,
consider a **local hosting provider with AI hosting**.

This is how we ended up at [mittwald AI hosting](https://www.mittwald.de/mstudio/ai-hosting),
since we already run several TYPO3 website on their servers.
They also offer the hosting of vector databases.

## 🛠️ Testing your keys

### OpenAI

* [Documentation](https://platform.openai.com/docs/api-reference/responses/create)

```bash
curl https://api.openai.com/v1/responses \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $OPENAI_API_KEY" \
  -d '{
    "model": "gpt-4.1",
    "input": "Tell me a three sentence bedtime story about a unicorn."
  }'

```

### Mistral

* [Documentation](https://docs.mistral.ai/getting-started/quickstart)

```bash
curl "https://api.mistral.ai/v1/chat/completions" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer DEIN_MISTRAL_API_KEY" \
  -d '{
    "model": "mistral-tiny",
    "messages": [
      {
        "role": "user",
        "content": "Wie ist das Wetter im Weltraum?"
      }
    ]
  }'
```

### Google API

* [Documentation](https://ai.google.dev/gemini-api/docs/api-key?hl=de)

```bash
curl "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent" \
  -H "Content-Type: application/json" \
  -H "x-goog-api-key: DEIN_API_KEY" \
  -d '{
    "contents": [{
      "parts": [{"text": "Was ist der Unterschied zwischen JSON und XML?"}]
    }]
  }'
```
