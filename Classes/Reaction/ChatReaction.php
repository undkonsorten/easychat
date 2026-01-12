<?php

namespace Undkonsorten\Easychat\Reaction;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Chat\Bridge\Session\MessageStore;
use Symfony\AI\Chat\Chat;
use Symfony\AI\Chat\InMemory\Store as InMemoryStore;
use Symfony\AI\Chat\MessageNormalizer;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Bridge\Generic\ModelCatalog;
use Symfony\AI\Platform\Bridge\Generic\PlatformFactory;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\PropagateResponseException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Reactions\Model\ReactionInstruction;
use TYPO3\CMS\Reactions\Reaction\ReactionInterface;

use Undkonsorten\Easychat\Domain\Repository\SessionRepository;
use Undkonsorten\Easychat\Services\DoctrineDbalMessageStore;

class ChatReaction implements ReactionInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
        private readonly ConnectionPool           $connectionPool,
        private readonly SessionRepository        $sessionRepository,
        private PersistenceManager                $persistenceManager,
        private readonly SerializerInterface      $serializer = new Serializer([
            new ArrayDenormalizer(),
            new MessageNormalizer(),
        ], [new JsonEncoder()]),
    ) {}


    const TABLE_NAME = 'tx_easychat_messages';

    const CONFIGURATION_TABLE_NAME = 'tx_easychat_configuration';

    /**
     * @inheritDoc
     */
    public static function getType(): string
    {
        return 'easychat-reaction';
    }

    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Reaction for easychat.';
    }

    /**
     * @inheritDoc
     */
    public static function getIconIdentifier(): string
    {
        return 'actions-chat';
    }

    /**
     * @inheritDoc
     */
    public function react(ServerRequestInterface $request, array $payload, ReactionInstruction $reaction): ResponseInterface
    {
        if(!$payload['messages'] && !count($payload['messages'])>0) {
            $result = $this->jsonResponse(['error' => "No messages given."], 400);
            throw new PropagateResponseException($result);
        }
        if(!$payload['sessionId']) {
            $result = $this->jsonResponse(['error' => "No session id given."], 400);
            throw new PropagateResponseException($result);
        }

        if(!$reaction->toArray()['easychat_configuration'] && $reaction->toArray()['easychat_configuration'] <= 0)
        {
            $result = $this->jsonResponse(['error' => "No configuration given."], 400);
            throw new PropagateResponseException($result);
        }

        $configuration = $this->connectionPool
            ->getConnectionForTable(self::CONFIGURATION_TABLE_NAME)
            ->select(
                ['*'],
                self::CONFIGURATION_TABLE_NAME,
                ['uid' => (int)$reaction->toArray()['easychat_configuration']],
            )
            ->fetchAssociative();

        $modelCatalog = new ModelCatalog([
            $configuration['model'] => [
                'class' => CompletionsModel::class,
                'capabilities' => [
                    Capability::INPUT_MESSAGES,
                    Capability::OUTPUT_TEXT,
                    Capability::OUTPUT_STREAMING,
                    Capability::OUTPUT_STRUCTURED,
                    Capability::INPUT_IMAGE,
                    Capability::TOOL_CALLING,
                ],
            ],
        ]);

        $platform = PlatformFactory::create($configuration['url'], $configuration['api_key'], HttpClient::create(), $modelCatalog);
        $agent = new Agent($platform, $configuration['model']);
        $this->sessionRepository->setup($payload);
        $chat = new Chat($agent, $this->sessionRepository);

        $messageHistory = new MessageBag(Message::forSystem($configuration['system_message']));

        // We add all messages except the last one
        foreach (array_slice($payload['messages'], 0, count($payload['messages']) -1) as $message) {
            if($message['role'] === 'assistant'){
                $messageHistory->add(Message::ofAssistant($message['text']));
            }
            if($message['role'] === 'user'){
                $messageHistory->add(Message::ofUser($message['text']));
            }
        }

        try{
            $answer = $chat->submit(Message::ofUser(end($payload['messages'])['text']));
        }catch (\Throwable $exception){
            $result = $this->jsonResponse(['error' => $exception->getMessage()], 500);
            throw new PropagateResponseException($result);
        }

        return $this->jsonResponse([
                'text' => $answer->getContent(),
                'role' => $answer->getRole(),
            ]
        );
    }

    private function jsonResponse(array $data, int $statusCode = 200): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(json_encode($data, JSON_THROW_ON_ERROR)));
    }
}
