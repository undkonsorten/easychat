<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Factories;

use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Bridge\SimilaritySearch\SimilaritySearch;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Store\Retriever;
use Undkonsorten\Easychat\Chat\ChatConfiguration;

/**
 * Builds the Symfony AI agent for a chatbot configuration: the completions platform, and with a
 * vector store the similarity search tool on top of it.
 *
 * The store is not set up here. Creating the collection belongs to indexing
 * (Indexing\VectorTargetFactory); the chat request path must not pay a vector store round trip
 * per message.
 */
class AgentFactory
{
    public function create(ChatConfiguration $configuration): AgentInterface
    {
        $row = $configuration->row;
        $platform = AiPlatformFactory::createCompletionsPlatform($row);

        if (!$configuration->hasVectorStore()) {
            return new Agent($platform, $configuration->model);
        }

        $store = StoreFactory::create(
            (string)$row['vector_db'],
            $row['vector_db_host'] . ':' . $row['vector_db_port'],
            (string)($row['vector_db_api_key'] ?? ''),
            (string)$row['vector_db_name'],
            (int)$row['vector_db_dimensions'],
        );
        $vectorizer = VectorizerFactory::create($row);

        $similaritySearch = new SimilaritySearch(new Retriever($store, $vectorizer));
        // Some models (e.g. gpt-oss) call tools beyond what we register (like a
        // trained-in "open_file" follow-up to a search call). FaultTolerantToolbox
        // turns that into a normal tool-result message the model can recover from,
        // instead of the whole request failing with ToolNotFoundException.
        $toolbox = new FaultTolerantToolbox(new Toolbox([$similaritySearch]));

        return new Agent($platform, $configuration->model, toolbox: $toolbox);
    }
}
