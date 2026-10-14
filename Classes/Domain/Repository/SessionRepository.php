<?php

namespace Undkonsorten\Easychat\Domain\Repository;

use Symfony\AI\Chat\ManagedStoreInterface;
use Symfony\AI\Chat\MessageNormalizer;
use Symfony\AI\Chat\MessageStoreInterface;
use Symfony\AI\Platform\Contract\Normalizer\Result\ToolCallNormalizer;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;
use Undkonsorten\Easychat\Domain\Model\Session;

/**
 * @extends Repository<Session>
 */
class SessionRepository extends Repository implements ManagedStoreInterface, MessageStoreInterface
{
    protected string $sessionId;

    protected int $configuration = 0;

    protected string $clientMarker = '';

    protected int $pid = 1;

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly SerializerInterface $serializer = new Serializer([
            new ArrayDenormalizer(),
            new MessageNormalizer(),
            // MessageNormalizer delegates the tool calls of assistant and tool call messages
            new ToolCallNormalizer(),
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

    /**
     * One conversation is one row, identified by the session id and the chatbot configuration
     * it belongs to. A visitor therefore has one conversation per chatbot under one session id.
     *
     * @param array{sessionId?: string, configuration?: int, clientMarker?: string} $options
     */
    public function setup(array $options = []): void
    {
        if (!isset($options['sessionId']) || $options['sessionId'] === '') {
            throw new \InvalidArgumentException('sessionId is missing', 1768240551);
        }
        $this->sessionId = (string)$options['sessionId'];
        $this->configuration = (int)($options['configuration'] ?? 0);
        $this->clientMarker = (string)($options['clientMarker'] ?? '');
    }

    public function drop(): void
    {
        $session = $this->findCurrent();
        if (!is_null($session)) {
            $this->remove($session);
            $this->persistenceManager->persistAll();
        }
    }

    public function save(MessageBag $messages): void
    {
        $session = $this->findCurrent();
        if (is_null($session)) {
            $session = GeneralUtility::makeInstance(Session::class);
            $session->setPid($this->pid);
            $session->setSessionId($this->sessionId);
            $session->setConfiguration($this->configuration);
            $session->setClientMarker($this->clientMarker);
            $session->setMessages($this->serializer->serialize($messages->getMessages(), 'json'));
            $this->add($session);
        } else {
            $session->setMessages($this->serializer->serialize($messages->getMessages(), 'json'));
            $this->update($session);
        }
        $this->persistenceManager->persistAll();
    }

    public function load(): MessageBag
    {
        $session = $this->findCurrent();
        if (is_null($session)) {
            return new MessageBag();
        }
        $messages = $this->serializer->deserialize($session->getMessages(), MessageInterface::class . '[]', 'json');
        return new MessageBag(...$messages);
    }

    /**
     * Whether the session id was issued to the visitor who holds this consent marker. Used to
     * decide if a presented session cookie may be continued, no matter which chatbot is asked.
     */
    public function hasSessionWithMarker(string $sessionId, string $clientMarkerHash): bool
    {
        return count($this->findBy(['sessionId' => $sessionId, 'clientMarker' => $clientMarkerHash])) > 0;
    }

    protected function findCurrent(): ?Session
    {
        return $this->findBy(['sessionId' => $this->sessionId, 'configuration' => $this->configuration])->getFirst();
    }

    /**
     * @param \DateInterval $dateInterval
     * @return array|QueryResultInterface
     * @throws InvalidQueryException
     */
    public function findOutOfInterval(\DateInterval $dateInterval)
    {
        $now = new \DateTime('now');
        $now->sub($dateInterval);
        $query = $this->createQuery();
        $query->matching(
            $query->lessThan('tstamp', $now->getTimestamp())
        );
        return $query->execute();
    }

}
