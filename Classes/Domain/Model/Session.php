<?php

namespace Undkonsorten\Easychat\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

class Session extends AbstractEntity
{
    protected string|null $sessionId = null;

    protected string|null $messages = null;

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

    public function getCreatedAt(): \DateTime
    {
        $createdAt = new \DateTime('now');
        $createdAt->setTimestamp($this->crdate);
        return $createdAt;
    }
}
