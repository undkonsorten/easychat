<?php

namespace Undkonsorten\Easychat\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

class Session extends AbstractEntity
{
    protected ?string $sessionId = null;

    protected ?string $messages = null;

    protected int $configuration = 0;

    protected string $clientMarker = '';

    /**
     * @var int
     */
    protected $crdate;

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function setSessionId(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function getMessages(): ?string
    {
        return $this->messages;
    }

    public function setMessages(?string $messages): void
    {
        $this->messages = $messages;
    }

    public function getConfiguration(): int
    {
        return $this->configuration;
    }

    public function setConfiguration(int $configuration): void
    {
        $this->configuration = $configuration;
    }

    public function getClientMarker(): string
    {
        return $this->clientMarker;
    }

    public function setClientMarker(string $clientMarker): void
    {
        $this->clientMarker = $clientMarker;
    }

    public function getCreatedAt(): \DateTime
    {
        $createdAt = new \DateTime('now');
        $createdAt->setTimestamp((int)$this->crdate);
        return $createdAt;
    }
}
