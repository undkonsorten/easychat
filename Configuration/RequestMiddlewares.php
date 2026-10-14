<?php

declare(strict_types=1);

use Undkonsorten\Easychat\Middleware\ChatEndpointMiddleware;

// After frontend authentication, so the frontend user groups are known for access checks on
// the content element and its page; before page resolution, so no page is rendered or cached
// for the endpoint.
return [
    'frontend' => [
        'undkonsorten/easychat/chat-endpoint' => [
            'target' => ChatEndpointMiddleware::class,
            'after' => [
                'typo3/cms-frontend/authentication',
            ],
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
        ],
    ],
];
