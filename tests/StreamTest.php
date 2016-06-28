<?php

use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Elastica\Connection;
use Elastica\Request;
use InterNations\Component\HttpMock\PHPUnit\HttpMockTrait;
use PHPUnit_Framework_TestCase as TestCase;


class StreamTest extends TestCase
{
    const HTTP_HOST = 'localhost';

    const HTTP_PORT = 9920;

    use HttpMockTrait;

    public static function setUpBeforeClass()
    {
        static::setUpHttpMockBeforeClass(self::HTTP_PORT, self::HTTP_HOST);
    }

    public static function tearDownAfterClass()
    {
        static::tearDownHttpMockAfterClass();
    }

    public function setUp()
    {
        $this->setUpHttpMock();
    }

    public function tearDown()
    {
        $this->tearDownHttpMock();
    }

    protected function createConnection($options = [])
    {
        $params = array_merge(
            [
                'port' => self::HTTP_PORT,
                'transport' => new Stream(),
            ],
            $options
        );
        return new Connection($params);
    }

    public function testGetTransport()
    {
        $stream = new Stream();
        $connection = $this->createConnection(['transport' => $stream]);

        $this->assertSame($stream, $connection->getTransportObject());
    }

    public function testSimpleGet()
    {
        $http_mock = $this->http->mock;
        $http_mock->when()
            ->methodIs('GET')
                ->pathIs('/index/type/test')
            ->then()
                ->body('{"hits": {"total": 123}}')
            ->end();

        $this->http->setUp();

        $connection = $this->createConnection();

        $request = new Request('/index/type/test', Request::GET, [], [], $connection);
        $response = $request->send();

        $data = $response->getData();
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals(123, $data['hits']['total']);
    }

    public function testGetWithCustomHeader()
    {
        $time = time();

        $http_mock = $this->http->mock;
        $http_mock
            ->when()
                ->methodIs('GET')
                ->pathIs('/custom_header')
                ->callback(
                    function (HttpRequest $request) use ($time) {
                        return $time == $request->headers->get('x-time');
                    }
                )
            ->then()
                ->body("It's right time!")
            ->end();

        $this->http->setup();

        $connection = $this->createConnection(
            [
                'config' => [
                    'headers' => [
                        'X-Time' => $time,
                    ],
                ],
            ]
        );

        $request = new Request('/custom_header', Request::GET, [], [], $connection);
        $response = $request->send();

        $data = $response->getData();
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals(['message' => "It's right time!"], $data);
    }

    public function testHttpRedirection()
    {
        $http_mock = $this->http->mock;
        $http_mock
            ->when()
                ->methodIs('GET')
                ->pathIs('/redirect')
            ->then()
                ->statusCode(301)
                ->header('Location', '/foo')
            ->end();
        $http_mock
            ->when()
                ->methodIs('GET')
                ->pathIs('/foo')
            ->then()
                ->body('ok')
            ->end();

        $this->http->setup();

        $connection = $this->createConnection();

        $request = new Request('/redirect', Request::GET, [], [], $connection);
        $response = $request->send();

        $data = $response->getData();
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals(['message' => "ok"], $data);
    }

    /**
     * @expectedException Elastica\Exception\ResponseException
     */
    public function testResponseException()
    {
        $http_mock = $this->http->mock;
        $http_mock->when()
            ->methodIs('GET')
                ->pathIs('/error')
            ->then()
                ->statusCode(500)
                ->body('{"error": "test"}')
            ->end();

        $this->http->setUp();

        $connection = $this->createConnection();

        $request = new Request('/error', Request::GET, [], [], $connection);
        $request->send();
    }

    public function testPartialShardFailureException()
    {
        $body = <<<JSON
{
  "_shards": {
    "total": 5,
    "successful": 4,
    "failed": 1
  }
}
JSON;
        $http_mock = $this->http->mock;
        $http_mock->when()
            ->methodIs('GET')
                ->pathIs('/shards_fail')
            ->then()
                ->statusCode(200)
                ->body($body)
            ->end();

        $this->http->setUp();

        $connection = $this->createConnection();

        $request = new Request('/shards_fail', Request::GET, [], [], $connection);
        $response = $request->send();

        $stats = $response->getShardsStatistics();
        $this->assertEquals(5, $stats['total']);
        $this->assertEquals(1, $stats['failed']);
        $this->assertEquals(4, $stats['successful']);
    }

    public function testGetWithPathConfig()
    {
        $http_mock = $this->http->mock;
        $http_mock->when()
            ->methodIs('GET')
                ->pathIs('/foo/bar')
            ->then()
                ->statusCode(200)
                ->body('ok')
            ->end();

        $this->http->setUp();

        $connection = $this->createConnection(['path' => 'foo']);

        $request = new Request('/bar');
        $request->setConnection($connection);
        $response = $request->send();

        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals(['message' => 'ok'], $response->getData());
    }

    public function testPostPlainText()
    {
        $http_mock = $this->http->mock;
        $http_mock
            ->when()
                ->methodIs('POST')
                ->pathIs('/test')
                ->callback(
                    function (HttpRequest $request) {
                        return 'body' === $request->getContent();
                    }
                )
            ->then()
                ->body('ok')
            ->end();

        $this->http->setUp();

        $connection = $this->createConnection();
        $connection->setConfig([
            'headers' => [
                'Content-Type' => 'text/plain',
            ]
        ]);
        $stream = $connection->getTransportObject();
        $stream->setParam('postWithRequestBody', true);

        $request = new Request('/test', Request::POST, 'body', [], $connection);
        $response = $request->send();

        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals(['message' => 'ok'], $response->getData());
    }

    public function testPostJsonContent()
    {
        $body = [
            'foo' => 'bar',
        ];

        $http_mock = $this->http->mock;
        $http_mock
            ->when()
                ->methodIs('POST')
                ->pathIs('/test')
                ->callback(
                    function (HttpRequest $request) use ($body) {
                        return json_encode($body) === $request->getContent();
                    }
                )
            ->then()
                ->body('ok')
            ->end();

        $this->http->setUp();

        $connection = $this->createConnection();
        $connection->setConfig([
            'headers' => [
                'Content-Type' => 'application/json',
            ]
        ]);
        $stream = $connection->getTransportObject();
        $stream->setParam('postWithRequestBody', true);

        $request = new Request('/test', Request::POST, $body, [], $connection);
        $response = $request->send();

        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals(['message' => 'ok'], $response->getData());
    }

    public function testPostEmptyContent()
    {
        $http_mock = $this->http->mock;
        $http_mock
            ->when()
                ->methodIs('POST')
                ->pathIs('/test')
                ->callback(
                    function (HttpRequest $request) {
                        return empty($request->getContent());
                    }
                )
            ->then()
                ->body('ok')
            ->end();

        $this->http->setUp();

        $connection = $this->createConnection();
        $stream = $connection->getTransportObject();
        $stream->setParam('postWithRequestBody', true);

        $request = new Request('/test', Request::POST, [], [], $connection);
        $response = $request->send();

        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals(['message' => 'ok'], $response->getData());
    }

    public function testGetWithQueryString()
    {
        $http_mock = $this->http->mock;
        $http_mock
            ->when()
                ->methodIs('GET')
                ->pathIs('/query?foo=1')
            ->then()
                ->body('ok')
            ->end();

        $this->http->setUp();

        $connection = $this->createConnection();

        $request = new Request('/query', Request::GET, [], ['foo' => '1'], $connection);
        $response = $request->send();

        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals(['message' => 'ok'], $response->getData());
    }

    /**
     * @expectedException Elastica\Exception\RuntimeException
     */
    public function testGetWithInvalidUrl()
    {
        $connection = new Connection(
            [
                'config' => [
                    'url' => 'foo://bar',
                ],
                'transport' => new Stream(),
            ]
        );
        $request = new Request('/query', Request::GET, [], [], $connection);
        $response = $request->send();
    }
}
