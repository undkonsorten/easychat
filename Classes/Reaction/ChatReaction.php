<?php

namespace Undkonsorten\Easychat\Reaction;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Bridge\SimilaritySearch\SimilaritySearch;
use Symfony\AI\Agent\Toolbox\AgentProcessor;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Chat\Chat;
use Symfony\AI\Chat\MessageNormalizer;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\PropagateResponseException;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Reactions\Model\ReactionInstruction;
use TYPO3\CMS\Reactions\Reaction\ReactionInterface;

use Undkonsorten\Easychat\Domain\Repository\SessionRepository;
use Undkonsorten\Easychat\Factories\AiPlatformFactory;
use Undkonsorten\Easychat\Factories\StoreFactory;
use Undkonsorten\Easychat\Factories\VectorizerFactory;

class ChatReaction implements ReactionInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
        private readonly ConnectionPool           $connectionPool,
        private readonly SessionRepository        $sessionRepository,
        private readonly PersistenceManager       $persistenceManager,
        private readonly SerializerInterface      $serializer = new Serializer([
            new ArrayDenormalizer(),
            new MessageNormalizer(),
        ], [new JsonEncoder()]),
    ) {}


    const TABLE_NAME = 'tx_easychat_messages';

    const CONFIGURATION_TABLE_NAME = 'tx_easychat_configuration';

    const COOKIE_NAME = 'easychat_session_id';

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
     * @throws \Exception
     */
    public function react(ServerRequestInterface $request, array $payload, ReactionInstruction $reaction): ResponseInterface
    {
        if(!$payload['messages'] && !count($payload['messages'])>0) {
            $result = $this->jsonResponse(['error' => "No messages given."], 400);
            throw new PropagateResponseException($result, 1072213738);
        }
        if(!$request->getCookieParams()[self::COOKIE_NAME]) {
            $result = $this->jsonResponse(['error' => "No session id given. Make sure there is a cookie named ".self::COOKIE_NAME], 400);
            throw new PropagateResponseException($result, 4131317075);
        }

        if(!$reaction->toArray()['easychat_configuration'] && $reaction->toArray()['easychat_configuration'] <= 0)
        {
            $result = $this->jsonResponse(['error' => "No configuration given."], 400);
            throw new PropagateResponseException($result, 4236347612);
        }
        $configuration = $this->connectionPool
            ->getConnectionForTable(self::CONFIGURATION_TABLE_NAME)
            ->select(
                ['*'],
                self::CONFIGURATION_TABLE_NAME,
                ['uid' => (int)$reaction->toArray()['easychat_configuration']],
            )
            ->fetchAssociative();

        $platform = AiPlatformFactory::createCompletionsPlatform($configuration);

        if($configuration['vector_db'] && $configuration['vector_db'] != 'none') {
            $store = StoreFactory::create(
                $configuration['vector_db'],
                $configuration['vector_db_host'].':'.$configuration['vector_db_port'],
                $configuration['vector_db_api_key'],
                $configuration['vector_db_name'],
                (int)$configuration['vector_db_dimensions'],
            );
            $store->setup();
            $vectorizer = VectorizerFactory::create($configuration);

            $similaritySearch = new SimilaritySearch($vectorizer, $store);
            // Some models (e.g. gpt-oss) call tools beyond what we register (like a
            // trained-in "open_file" follow-up to a search call). FaultTolerantToolbox
            // turns that into a normal tool-result message the model can recover from,
            // instead of the whole request failing with ToolNotFoundException.
            $toolbox = new FaultTolerantToolbox(new Toolbox([$similaritySearch]));
            $processor = new AgentProcessor($toolbox);
            $agent = new Agent($platform, $configuration['model'], [$processor], [$processor]);
        }else{
            $agent = new Agent($platform, $configuration['model']);
        }



        $this->sessionRepository->setup(['sessionId' => $request->getCookieParams()[self::COOKIE_NAME]]);
        $chat = new Chat($agent, $this->sessionRepository);

        $session = $this->sessionRepository->findBy(['session_id' => $request->getCookieParams()[self::COOKIE_NAME]])->getFirst();

        if(is_null($session)){
            $messageHistory = new MessageBag(Message::forSystem($configuration['system_message']));
            $chat->initiate($messageHistory);
        }

        try{
            $answer = $chat->submit(Message::ofUser(end($payload['messages'])['text']));
        }catch (\Throwable $exception){
            $result = $this->jsonResponse(['error' => $exception->getMessage()], 500);
            throw new PropagateResponseException($result, 4613340330);
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
