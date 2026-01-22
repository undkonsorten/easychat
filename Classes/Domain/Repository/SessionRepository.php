<?php

namespace Undkonsorten\Easychat\Domain\Repository;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use Undkonsorten\Easychat\Domain\Model\Session;
use Symfony\AI\Chat\ManagedStoreInterface;
use Symfony\AI\Chat\MessageNormalizer;
use Symfony\AI\Chat\MessageStoreInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Repository;

class SessionRepository extends Repository implements ManagedStoreInterface, MessageStoreInterface
{
    protected string $sessionId;

    protected int $pid = 1;

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly SerializerInterface      $serializer = new Serializer([
            new ArrayDenormalizer(),
            new MessageNormalizer(),
        ], [new JsonEncoder()]),
    ) {
        $this->pid = $this->extensionConfiguration
            ->get('easychat')['storagePid'] ?? 1;
        parent::__construct();
    }

    public function initializeObject(): void
    {
        $querySettings = $this->createQuery()->getQuerySettings();
        $querySettings->setStoragePageIds([$this->pid]);
        $this->setDefaultQuerySettings($querySettings);
    }

    public function setup(array $options = []): void
    {
        if(is_null($options['sessionId'])){
            throw new \InvalidArgumentException('sessionId is null',1768240551);
        }
        $this->sessionId = $options['sessionId'];
        #@todo do we want this?
        #$this->sessionId = $options['pid'] ?? $this->extensionConfiguration
        #    ->get('storagePid') ?? 1;
    }

    public function drop(): void
    {
        $session = $this->findBy(['session_id' => $this->sessionId])->getFirst();
        if(!is_null($session)){
            $this->remove($session);
            $this->persistenceManager->persistAll();
        }
    }

    public function save(MessageBag $messages): void
    {
        $session = $this->findBy(['session_id' => $this->sessionId])->getFirst();
        if(is_null($session)){
            $session = GeneralUtility::makeInstance(Session::class);
            $session->setSessionId($this->sessionId);
            $session->setMessages($this->serializer->serialize($messages->getMessages(), 'json'));
            $this->add($session);
        }else{
            $session->setMessages($this->serializer->serialize($messages->getMessages(), 'json'));
            $this->update($session);
        }
        $this->persistenceManager->persistAll();
    }

    public function load(): MessageBag
    {
        $session = $this->findBy(['session_id' => $this->sessionId])->getFirst();
        if(is_null($session)){
            return new MessageBag();
        }
        $messages = $this->serializer->deserialize($session->getMessages(), MessageInterface::class.'[]', 'json');
        return new MessageBag(...$messages);
    }

}
