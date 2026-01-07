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
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\JsonSerializableNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\PropagateResponseException;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Reactions\Model\ReactionInstruction;
use TYPO3\CMS\Reactions\Reaction\ReactionInterface;
use Undkonsorten\Easychat\Domain\Model\Gen\ChatCompletionRequestUserMessage;
use Undkonsorten\Easychat\Services\DatabaseMessageStore;
use Undkonsorten\Easychat\Services\DoctrineDbalMessageStore;

class ChatReaction implements ReactionInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ConnectionPool $connectionPool,
    ) {}


    const TABLE_NAME = 'easychat_messages';
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
        $modelCatalog = new ModelCatalog([
            'gpt-oss-120b' => [
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

        if(!$payload['messages'] && !$payload['messages'][0]['text']) {
            $result = $this->jsonResponse(['error' => "No messages given."], 400);
            throw new PropagateResponseException($result);
        }

        //@todo this needs to be configured which PlatformFactory should be used
        $platform = PlatformFactory::create('https://llm.aihosting.mittwald.de', 'sk-ZM4KOn0XVNjrGKdFeS3JYg', HttpClient::create(), $modelCatalog);


        /* @todo needs implementation   */

        $store = new DoctrineDbalMessageStore(
            self::TABLE_NAME,
            $this->connectionPool
                ->getConnectionForTable(self::TABLE_NAME),
        );

        $agent = new Agent($platform, 'gpt-oss-120b');
        /* @todo use DatabaseMessageStore */
        $chat = new Chat($agent, $store);

        $systemMessages = new MessageBag(
            Message::forSystem('You are very depressive.'),
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
