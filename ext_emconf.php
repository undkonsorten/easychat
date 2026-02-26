<?php

/** @noinspection PhpUndefinedVariableInspection */
$EM_CONF[$_EXTKEY] = [
    'title' => 'Easychat: The ai chatbot/assistent from heaven',
    'description' => 'A lightweight chatbot/assistant without third party chat tools.
    All you need is TYPO3 and an LLM endpoint. Works with nearly any LLM (ollama, chatgpt, mistral, gemini etc)
    Focused on privacy and data protection (DSGVO)',
    'category' => 'Frontend Plugins',
    'author' => 'Eike Starkmann',
    'author_email' => 'es@undkonsorten.com',
    'author_company' => 'undkonsorten GbR',
    'state' => 'alpha',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-13.99.99',
        ],
        'conflicts' => [
        ],
        'suggests' => [
        ],
    ],
];
