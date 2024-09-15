<?php
namespace Ubiquity\utils\http\foundation;



use Swow\Psr7\Message\Request;
use Swow\Psr7\Message\Response;

/**
 * Http instance for Swow.
 * Ubiquity\utils\http\foundation$SwowHttp
 * This class is part of Ubiquity
 *
 * @author jcheron <myaddressmail@gmail.com>
 * @version 1.0.1
 */
class SwowHttp extends AbstractHttp {

	private $headers = [];

	private $responseCode = 200;

	private $datas;

    private $response;
    private $request;

    public function __construct(Response $response, Request $request) {
        $this->response = $response;
        $this->request = $request;
        $this->headers = $this->request->getHeaders();
    }

	public function getAllHeaders() {
		return $this->headers;
	}

	public function setDatas($datas) {
		return $this->datas;
	}

	public function header($key, $value, bool $replace = true, int $http_response_code = 0) {
		$this->headers[$key] = $value;
		if ($http_response_code != 0) {
			$this->responseCode = $http_response_code;
		}
        $this->response->setHeader($key, $value);
	}

	/**
	 *
	 * @return int
	 */
	public function getResponseCode() {
		return $this->responseCode;
	}

	/**
	 *
	 * @param int $responseCode
	 */
    public function setResponseCode($responseCode) {
        if ($responseCode !== null) {
            $this->responseCode = $responseCode;
            $this->response->setStatus($responseCode);
            return $responseCode;
        }
        return false;
    }

	public function headersSent(string &$file = null, int &$line = null) {
        // Swow handles headers in a way that may not use PHP's headers_sent()
        // Direct interaction with PHP's headers_sent might not be relevant in Swow
        return false;
	}

	public function getInput() {
		return $this->datas;
	}
}

