<?php

namespace Undkonsorten\Easychat\Services;

use Symfony\AI\Chat\ManagedStoreInterface;
use Symfony\AI\Chat\MessageStoreInterface;
use Symfony\AI\Platform\Message\MessageBag;

class DatabaseMessageStore implements ManagedStoreInterface, MessageStoreInterface
{

    public function setup(array $options = []): void
    {
        // TODO: Implement setup() method.
    }

    public function drop(): void
    {
        // TODO: Implement drop() method.
    }

    public function save(MessageBag $messages): void
    {
        // TODO: Implement save() method.
    }

    public function load(): MessageBag
    {
        // TODO: Implement load() method.
    }
}
