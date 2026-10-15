<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Meta\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use SilverShop\ConversionTracking\Meta\MetaCapiClient;
use SilverStripe\Dev\SapphireTest;

/**
 * Pins the CAPI transport against a mocked Guzzle handler: POST to /{version}/{pixelId}/events with the
 * access_token on the query string and a JSON body.
 */
class MetaCapiClientTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testSendPostsJsonToEventsEndpoint(): void
    {
        $transactions = [];
        $mock = new MockHandler([new Response(200, [], (string) json_encode(['events_received' => 1]))]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($transactions));

        $client = new MetaCapiClient(
            'PIXEL123',
            'TOKENabc',
            'v21.0',
            MetaCapiClient::DEFAULT_API_BASE,
            ['handler' => $stack]
        );
        $result = $client->send(['data' => [['event_name' => 'Purchase', 'event_id' => 'purchase.42']]]);

        $this->assertSame(1, $result['events_received']);
        $this->assertCount(1, $transactions);

        /** @var RequestInterface $request */
        $request = $transactions[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/v21.0/PIXEL123/events', $request->getUri()->getPath());
        $this->assertStringContainsString('access_token=TOKENabc', $request->getUri()->getQuery());
        $this->assertStringStartsWith('application/json', $request->getHeaderLine('Content-Type'));

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('purchase.42', $body['data'][0]['event_id']);
    }
}
