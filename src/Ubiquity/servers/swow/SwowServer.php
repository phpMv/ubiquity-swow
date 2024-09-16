<?php
namespace Ubiquity\servers\swow;


use Swow\Coroutine;
use Swow\CoroutineException;
use Swow\Errno;
use Swow\Http\Protocol\ProtocolException;
use Swow\Psr7\Message\Request;
use Swow\Psr7\Message\Response;
use Swow\Psr7\Server\Server;
use Swow\Socket;
use Swow\SocketException;
use Ubiquity\utils\base\MimeType;
use Ubiquity\utils\http\foundation\SwowHttp;

/**
 * Ubiquity\servers\swow$SwowServer
 * This class is part of Ubiquity
 *
 * @author jcheron <myaddressmail@gmail.com>
 * @version 1.0.0
 */

class SwowServer {

    private $server;
    private $config;
    private $basedir;
    private $options;

    private $httpInstance;

    public function __construct() {
        $this->server = new Server();
    }

    private function configure($options) {
        $this->options = $options;
        // Configuration-specific code for Swow can be added here
    }

    public function init($config, $basedir) {
        $this->config = $config;
        $this->basedir = $basedir;
        \Ubiquity\controllers\Startup::init($config);
    }

    public function getOption(string $key) {
        $option = $this->options[$key] ?? null;
        if ($option === null) {
            throw new \InvalidArgumentException(sprintf('Parameter not found: %s', $key));
        }
        return $option;
    }

    public function setOptions($options = []) {
        $this->options = $options;
    }

    protected function populateServerArray(Request $request) {
        $_SERVER = []; // Clear the existing $_SERVER array

        // Request method
        $_SERVER['REQUEST_METHOD'] = $request->getMethod();

        // Request URI and Query String
        $uri = $request->getUri();
        $_SERVER['REQUEST_URI'] = $uri->getPath();
        $_SERVER['QUERY_STRING'] = $uri->getQuery();

        // Server protocol
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/' . $request->getProtocolVersion();

        // Headers
        foreach ($request->getHeaders() as $header => $values) {
            $headerName = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
            $_SERVER[$headerName] = implode(', ', $values);
        }

        // Handle HTTP_X_REQUESTED_WITH for AJAX requests
        if ($request->hasHeader('x-requested-with')) {
            $_SERVER['HTTP_X_REQUESTED_WITH'] = $request->getHeader('x-requested-with')[0];
        }

        // Host and Port
        $_SERVER['SERVER_NAME'] = $request->getHeader('host')[0] ?? 'localhost';
        $_SERVER['SERVER_PORT'] = $request->getUri()->getPort() ?? 80;

        // Other necessary server variables
        $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $uri->getPath();
        $_SERVER['REQUEST_TIME'] = time();
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);

        // Content-type and length (if present)
        if ($request->hasHeader('content-type')) {
            $_SERVER['CONTENT_TYPE'] = $request->getHeader('content-type')[0];
        }
        if ($request->hasHeader('content-length')) {
            $_SERVER['CONTENT_LENGTH'] = $request->getHeader('content-length')[0];
        }
    }


    public function run($host, $port, $options = []) {
        $this->setOptions($options);
        $backlog = (int) ($options['SERVER_BACKLOG']??Socket::DEFAULT_BACKLOG);
        $this->server->bind($host, $port)->listen($backlog);
        $_SERVER['REMOTE_ADDR'] = $host;
        $_SERVER['REMOTE_PORT'] = $port;
        while (true) {
            try {
                $connection = null;
                $connection = $this->server->acceptConnection();
                $self=$this;
                Coroutine::run(static function () use ($connection,$self): void {
                    try {
                        while (true) {
                            $request = null;
                            try {
                                $request = $connection->recvHttpRequest();
                                $response = $self->handle($request, new Response());
                                $connection->sendHttpResponse($response);
                            } catch (ProtocolException $exception) {
                                $connection->error($exception->getCode(), $exception->getMessage(), true);
                                break;
                            }
                            if (!$connection->shouldKeepAlive()) {
                                break;
                            }
                        }
                    } catch (\Exception $e) {
                        // you can log error here
                    } finally {
                        $connection->close();
                    }
                });
            } catch (SocketException|CoroutineException $exception) {
                if (\in_array($exception->getCode(), [Errno::EMFILE, Errno::ENFILE, Errno::ENOMEM], true)) {
                    sleep(1);
                } else {
                    break;
                }
            }
        }
    }

    public function handle(Request $request, Response $response) {
        $this->httpInstance = new SwowHttp($response, $request);
        \Ubiquity\controllers\Startup::setHttpInstance( $this->httpInstance);
        $response->setHeader('Date', \gmdate('D, d M Y H:i:s') . ' GMT');
        $_GET['c'] = '';
        $this->populateServerArray($request);
        $uriInfos = \Ubiquity\utils\http\URequest::parseURI($request->getUri(), $this->basedir);
        $uri = $uriInfos['uri'];

        if ($uriInfos['isAction']) {
            $_GET['c'] = $uri;
        } else {
            if ($uriInfos['file']) {
                $file = $this->basedir . '/' . $uri;
                $mime = MimeType::getFileMimeType($file);
                $response->setHeader('Content-Type', $mime . '; charset=utf-8');
                $response->getBody()->write(\file_get_contents($file));
                return $response;
            } else {
                $response->setStatus(404);
                $response->setHeader('Content-Type', 'text/plain; charset=utf-8');
                $response->getBody()->write($uri . ' not found!');
                return $response;
            }
        }

        $this->httpInstance->setDatas($request->getBody()->getContents());
        \ob_start();
        \Ubiquity\controllers\StartupAsync::forward($_GET['c']);
        $response->getBody()->write(\ob_get_clean());
        return $response;
    }
}
