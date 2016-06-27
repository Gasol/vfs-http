<?php

use Elastica\Exception\PartialShardFailureException;
use Elastica\Exception\ResponseException;
use Elastica\Exception\RuntimeException;
use Elastica\JSON;
use Elastica\Request;
use Elastica\Response;
use Elastica\Transport\AbstractTransport;

class Stream extends AbstractTransport
{
    protected $scheme = 'http';

    public function exec(Request $request, array $params)
    {
        $base_uri = $this->getBaseUri();
        $base_uri .= $request->getPath();

        $query = $request->getQuery();
        if (!empty($query)) {
            $base_uri .= '?' . http_build_query($query);
        }

        $scheme = parse_url($base_uri, PHP_URL_SCHEME);
        $context = stream_context_create(
            [
                $scheme => $this->getStreamOptions($request),
            ]
        );
        $start = microtime(true);

        $fp = @fopen($base_uri, 'r', false, $context);
        if (!$fp) {
            $error = error_get_last();
            $message = $error ? $error['message'] : "Unable to open $base_uri";
            throw new RuntimeException($message);
        }
        $response_string = stream_get_contents($fp);

        $end = microtime(true);

        $meta_data = stream_get_meta_data($fp);
        fclose($fp);

        $http_status_code = 0;
        if (0 === strpos($meta_data['wrapper_type'], 'http')) {
            $wrapper_data = $meta_data['wrapper_data'];
            if (is_array($wrapper_data)) {
                foreach ($wrapper_data as $header) {
                    if (3 !== sscanf($header, 'HTTP/%f %d %s', $http_version, $http_status_code, $http_status)) {
                        continue;
                    }
                    if ($http_status_code < 400 && $http_status_code >= 300) {
                        continue;
                    }
                    break;
                }
            }
        }

        $response = new Response($response_string, $http_status_code);
        $response->setQueryTime($end - $start);
        $response->setTransferInfo($meta_data);

        if ($response->hasError()) {
            throw new ResponseException($request, $response);
        }

        if ($response->hasFailedShards()) {
            throw new PartialShardFailureException($request, $response);
        }

        return $response;
    }

    protected function getBaseUri()
    {
        $connection = $this->getConnection();

        $url = $connection->hasConfig('url') ? $connection->getConfig('url') : '';
        if (empty($url)) {
            $scheme = $this->scheme;
            $host = $connection->getHost();
            $port = $connection->getPort();
            $url = "$scheme://$host:$port";

            $path = $connection->getPath();
            if (strlen($path)) {
                if (0 !== strpos($path, '/')) {
                    $url .= '/';
                }
                $url .= $path;
            }

        }

        return $url;
    }

    protected function getStreamOptions($request)
    {
        $connection = $this->getConnection();
        $method = $request->getMethod();

        $opts = [
            'method' => $method,
            'ignore_errors' => true,
        ];

        if ($this->hasParam('postWithRequestBody') && $this->getParam('postWithRequestBody') == true) {
            $method = Request::POST;
            $content = $this->getBody($request);
            if (isset($content)) {
                $opts['content'] = $content;
            }
        }

        $header = $this->getHeaderString($connection);
        if (strlen($header)) {
            $opts['header'] = $header;
        }

        $timeout = $connection->getTimeout();
        if ($timeout > 0) {
            $opts['timeout'] = $timeout;
        }

        return $opts;
    }

    protected function getHeaderString($connection)
    {
        $headers = $connection->hasConfig('headers') ? $connection->getConfig('headers') : [];
        if (empty($headers)) {
            return '';
        }

        $header_string = '';
        foreach ($headers as $name => $value) {
            $header_string .= "$name: $value\r\n";
        }
        return $header_string;
    }

    protected function getBody($request)
    {
        $data = $request->getData();
        if (empty($data) && '0' !== $data) {
            return;
        }

        if (is_array($data)) {
            $content = JSON::stringify($data, 'JSON_ELASTICSEARCH');
        } else {
            $content = $data;
        }
        $content = str_replace('\/', '/', $content);
        return $content;
    }
}
