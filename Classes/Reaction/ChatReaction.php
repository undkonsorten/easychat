<?php

namespace Undkonsorten\Easychat\Reaction;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Chat\Chat;
use Symfony\AI\Chat\InMemory\Store as InMemoryStore;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Bridge\Generic\ModelCatalog;
use Symfony\AI\Platform\Bridge\Generic\PlatformFactory;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\HttpClient\HttpClient;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\PropagateResponseException;
use TYPO3\CMS\Reactions\Model\ReactionInstruction;
use TYPO3\CMS\Reactions\Reaction\ReactionInterface;

use Undkonsorten\Easychat\Services\DoctrineDbalMessageStore;

class ChatReaction implements ReactionInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ConnectionPool $connectionPool,
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
        if(!$payload['messages'] && !$payload['messages'][0]['text']) {
            $result = $this->jsonResponse(['error' => "No messages given."], 400);
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

        //@todo this needs to be configured which PlatformFactory should be used
        $platform = PlatformFactory::create($configuration['url'], $configuration['api_key'], HttpClient::create(), $modelCatalog);


        /* @todo needs implementation   */

        $store = new DoctrineDbalMessageStore(
            self::TABLE_NAME,
            $this->connectionPool
                ->getConnectionForTable(self::TABLE_NAME),
        );

        $agent = new Agent($platform, $configuration['model']);
        /* @todo use DatabaseMessageStore */
        $chat = new Chat($agent, $store);

        $systemMessages = new MessageBag(
            Message::forSystem($configuration['system_message']),
        );
        $chat->initiate($systemMessages);
        try{
            $answer = $chat->submit(Message::ofUser($payload['messages'][0]['text']));
        }catch (\Throwable $exception){
            $result = $this->jsonResponse(['error' => $exception->getMessage()], 500);
            throw new PropagateResponseException($result);
        }
        #$encoders = [new JsonEncoder()];
        #$normalizers = [new ObjectNormalizer()];
        #$serializer = new Serializer($normalizers, $encoders);

        #$jsonContent = $serializer->serialize($answer, 'json');

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
