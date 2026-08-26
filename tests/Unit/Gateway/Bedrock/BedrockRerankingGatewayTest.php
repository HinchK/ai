<?php

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\BedrockRuntime\Exception\BedrockRuntimeException;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Gateway\Bedrock\BedrockRerankingGateway;
use Laravel\Ai\Providers\BedrockProvider;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\RankedDocument;

beforeEach(function (): void {
    $this->dispatcher = Mockery::mock(Dispatcher::class);
});

afterEach(function (): void {
    Mockery::close();
});

function unitBedrockClient(MockHandler $mock): BedrockRuntimeClient
{
    return new BedrockRuntimeClient([
        'region' => 'us-east-1',
        'version' => '2023-09-30',
        'credentials' => false,
        'retries' => 0,
        'handler' => $mock,
    ]);
}

function unitBedrockRerankingGateway(BedrockRuntimeClient $client): BedrockRerankingGateway
{
    return new class($client) extends BedrockRerankingGateway
    {
        public function __construct(private BedrockRuntimeClient $stub) {}

        protected function createBedrockClient(Provider $provider, ?int $timeout = null): BedrockRuntimeClient
        {
            return $this->stub;
        }
    };
}

function unitBedrockProvider(Dispatcher $dispatcher): BedrockProvider
{
    return new BedrockProvider(
        config: [
            'name' => 'bedrock',
            'driver' => 'bedrock',
            'region' => 'us-east-1',
            'use_default_credential_provider' => false,
        ],
        events: $dispatcher,
    );
}

test('reranking request includes query, documents, and api version', function (): void {
    $mock = new MockHandler([new Result([
        'body' => Utils::streamFor(json_encode([
            'results' => [
                ['index' => 0, 'relevance_score' => 0.95],
                ['index' => 1, 'relevance_score' => 0.12],
            ],
        ])),
    ])]);

    unitBedrockRerankingGateway(unitBedrockClient($mock))->rerank(
        unitBedrockProvider($this->dispatcher),
        'cohere.rerank-v3-5:0',
        ['Laravel is a PHP framework', 'React is a JS library'],
        'What is Laravel?',
    );

    $command = $mock->getLastCommand();

    expect($command['modelId'])->toBe('cohere.rerank-v3-5:0')
        ->and(json_decode($command['body'], true))->toBe([
            'query' => 'What is Laravel?',
            'documents' => ['Laravel is a PHP framework', 'React is a JS library'],
            'api_version' => 2,
        ]);
});

test('reranking request includes top_n when limit set', function (): void {
    $mock = new MockHandler([new Result([
        'body' => Utils::streamFor(json_encode([
            'results' => [
                ['index' => 0, 'relevance_score' => 0.95],
            ],
        ])),
    ])]);

    unitBedrockRerankingGateway(unitBedrockClient($mock))->rerank(
        unitBedrockProvider($this->dispatcher),
        'cohere.rerank-v3-5:0',
        ['Doc A', 'Doc B', 'Doc C'],
        'query',
        limit: 2,
    );

    expect(json_decode($mock->getLastCommand()['body'], true)['top_n'])->toBe(2);
});

test('reranking response is correctly parsed into RankedDocuments', function (): void {
    $client = unitBedrockClient(new MockHandler([new Result([
        'body' => Utils::streamFor(json_encode([
            'results' => [
                ['index' => 0, 'relevance_score' => 0.95],
                ['index' => 1, 'relevance_score' => 0.12],
            ],
        ])),
    ])]));

    $response = unitBedrockRerankingGateway($client)->rerank(
        unitBedrockProvider($this->dispatcher),
        'cohere.rerank-v3-5:0',
        ['Laravel is a PHP framework', 'React is a JS library'],
        'What is Laravel?',
    );

    expect($response)->toHaveCount(2)
        ->and($response->first())->toBeInstanceOf(RankedDocument::class)
        ->and($response->first()->index)->toBe(0)
        ->and($response->first()->document)->toBe('Laravel is a PHP framework')
        ->and($response->first()->score)->toBe(0.95)
        ->and($response->meta->provider)->toBe('bedrock')
        ->and($response->meta->model)->toBe('cohere.rerank-v3-5:0');
});

test('reranking maps documents by index when results are returned out of order', function (): void {
    $client = unitBedrockClient(new MockHandler([new Result([
        'body' => Utils::streamFor(json_encode([
            'results' => [
                ['index' => 2, 'relevance_score' => 0.91],
                ['index' => 0, 'relevance_score' => 0.42],
                ['index' => 1, 'relevance_score' => 0.10],
            ],
        ])),
    ])]));

    $response = unitBedrockRerankingGateway($client)->rerank(
        unitBedrockProvider($this->dispatcher),
        'cohere.rerank-v3-5:0',
        ['Doc A', 'Doc B', 'Doc C'],
        'query',
    );

    $ranked = $response->collect();

    expect($ranked[0]->index)->toBe(2)
        ->and($ranked[0]->document)->toBe('Doc C')
        ->and($ranked[0]->score)->toBe(0.91)
        ->and($ranked[1]->index)->toBe(0)
        ->and($ranked[1]->document)->toBe('Doc A');
});

test('reranking throttling maps to rate limited exception', function (): void {
    unitBedrockRerankingGateway(unitBedrockClient(new MockHandler([
        mockBedrockRerankingUnitException('ThrottlingException', 429),
    ])))->rerank(
        unitBedrockProvider($this->dispatcher),
        'cohere.rerank-v3-5:0',
        ['Doc A', 'Doc B'],
        'query',
    );
})->throws(RateLimitedException::class);

function mockBedrockRerankingUnitException(string $awsErrorCode, int $statusCode = 400, string $message = 'Bedrock error'): BedrockRuntimeException
{
    return new class($awsErrorCode, $statusCode, $message) extends BedrockRuntimeException
    {
        public function __construct(
            private string $awsErrorCode,
            private int $httpStatus,
            string $message,
        ) {
            Exception::__construct($message, $httpStatus);
        }

        public function getAwsErrorCode(): string
        {
            return $this->awsErrorCode;
        }

        public function getStatusCode(): int
        {
            return $this->httpStatus;
        }
    };
}
