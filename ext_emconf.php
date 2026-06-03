<?php

/** @noinspection PhpUndefinedVariableInspection */
$EM_CONF[$_EXTKEY] = [
    'title' => 'EasyChat – TYPO3 standalone AI Chatbot',
    'description' => 'A TYPO3 chatbot/assistant without third party tools. Only TYPO3 + LLM endpoint needed (ChatGpt, Mistral...). Privacy focused (GDPR). Chats stored in TYPO3 only.',
    'category' => 'Frontend Plugins',
    'author' => 'Eike Starkmann',
    'author_email' => 'es@undkonsorten.com',
    'author_company' => 'undkonsorten GbR',
    'state' => 'alpha',
    'version' => '0.1.1',
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
