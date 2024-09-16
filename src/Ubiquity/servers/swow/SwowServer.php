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

    public function run($host, $port, $options = []) {
        $this->setOptions($options);
        $backlog = (int) ($options['SERVER_BACKLOG']??Socket::DEFAULT_BACKLOG);
        $this->server->bind($host, $port)->listen($backlog);
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
