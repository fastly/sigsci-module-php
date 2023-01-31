<?php

/*
 * Signal Sciences Corp. --  Pure PHP Module
 *
 * (c) 2015-2021 Signal Sciences Corp.
 *
 * Proprietary and Confidential - Do Not Distribute
 *
 */

// Perform a version check.
// NOTE: We only support PHP >= 5.3
if (version_compare(phpversion(), "5.3", "<")) {

    /**
     * SigSciModule
     *
     * Stubb class to prevent breakage for older
     * PHP versions.
     *
     */
    class SigSciModule
    {
        public function preRequest()
        {return false;}
        public function postRequest()
        {return null;}
        public function block()
        {return false;}
        public function agentResponseCode()
        {return null;}
        public function agentRequestID()
        {return null;}
        public function agentMeta()
        {return null;}
        public function agentTags()
        {return null;}
    }

} else {

    // test for C module -- https://github.com/msgpack/msgpack-php
    if (!function_exists("msgpack_pack")) {
        // No?  try pure PHP version
        //   -- https://github.com/onlinecity/msgpack-php
        require "msgpack.php";
    }

    /**
     * SigSciModule
     *
     * Sends HTTP requests to Signal Sciences Agent
     * for review and blocks the request depending on
     * the request payload.
     *
     * This works on all platforms if PHP >= 5.4
     *
     * Usage:
     *
     *    // Basic Usage
     *    $sigsci = new SigSciModuleSimple();
     *    if ($sigsci->block()){
     *        echo "BLOCKED\n";
     *        http_response_code(406);
     *        exit();
     *    }
     *
     *    // Set custom config for the module
     *    $sigsci_conf = array('socket_address' => '/var/tmp/sigsci-lua.sock');
     *    $sigsci = new SigSciModuleSimple($config);
     *
     */
    class SigSciModule
    {
        // Base parameter configuration
        public $config = array(
            'max_post_size' => 100000, /* ignore posts bigger than this */
            'read_timeout_microseconds' => 100000, /* fail open if agent calls take longer than this */
            'write_timeout_microseconds' => 100000, /* fail open if agent calls take longer than this */
            // 'timeout_microseconds' => 500000, /* fail open if agent calls take longer than this */
            'socket_domain' => AF_UNIX, /* INET or UNIX */
            'socket_address' => "/var/run/sigsci.sock",
            'socket_port' => 0,
            'body_methods' => array("POST", "PUT", "PATCH"),
            'anomaly_size' => 524288, /* if output is bigger size than this, send info to SigSci */
            'anomaly_duration' => 1000, /* if request length is greater than this (millisecond), report back */
        );

        // Agent response from RPC
        protected $agentResponse = array();

        protected $moduleVersion = "sigsci-module-php 2.1.0";

        // time when request started
        protected $requestStart = 0;

        // PHP-ISM: PHP does not have a way of capturing total output
        //  sent back to client.  This callback is used by
        //  ob_start to count the bytes sent back
        protected $responseSize = 0;

        // the copy of $_SERVER
        protected $cgi = array();

        /**
         * __construct
         *
         */
        public function __construct($config = array(), $server = null)
        {
            foreach ($config as $k => $v) {
                $this->config[$k] = $v;
            }
            if ($server === null) {
                $server = $_SERVER;
            }
            $this->cgi = $server;
        }

        /**
         * block
         *
         * Block is a helper and returns true if the
         * request should be blocked
         */
        public function block()
        {
            $ret = false;
            if ($this->agentResponseCode() >= 300 && $this->agentResponseCode() <= 599) {
                $ret = true;
            }
            return $ret;
        }

        /**
         * agentResponseCode returned by the agent
         *
         */
        public function agentResponseCode()
        {
            // Capture the WAFResponse
            if (!isset($this->agentResponse['WAFResponse'])) {
                return -1;
            }
            return (int) $this->agentResponse['WAFResponse'];
        }

        /**
         * agentRequestID
         *
         * Returns the RequestID from the signalsciecnes
         * agent
         */
        public function agentRequestID()
        {
            if (!isset($this->agentResponse['RequestID'])) {
                return "";
            }
            return $this->agentResponse['RequestID'];
        }

        /**
         * agentMeta returns a mapping of meta data from the agent response
         *
         */
        public function agentMeta()
        {
            $ary = array();
            if (!isset($this->agentResponse['RequestHeaders'])) {
                return $ary;
            }
            // is an array of array(key, valye)
            //  convert to array(key) -> value
            foreach ($this->agentResponse['RequestHeaders'] as $header) {
                $ary[$header[0]] = $header[1];
            }
            return $ary;
        }

        /**
         * agentTags
         *
         * Returns an array of the detected tags in a request
         */
        public function agentTags()
        {
            $meta = $this->agentMeta();
            if (!isset($meta['X-SigSci-Tags'])) {
                return array();
            }
            return explode(",", $meta['X-SigSci-Tags']);
        }

        /**
         * preRequest
         *
         * PreRequest is called BEFORE a page is rendered
         *  Return true if decision succeeded, call block afterwards to see
         *  Returns false if an error occurred
         */
        public function preRequest(&$errstr)
        {
            // This creates a filter that just is for capturing output size
            ob_start(array($this, "responseCounter"));

            $errstr = "";
            $this->requestStart = $this->nowMillis();

            // Establish the Agent payload
            $msg = $this->requestMsgCore();
            if ($this->readPost()) {
                $msg['PostBody'] = $this->postData();
            }

            // Send the PreRequest to the RPC
            $resp = $this->sendRPC("RPC.PreRequest", $msg, $errstr);
            if ($resp === false) {
                return false;
            }
            $this->agentResponse = $resp;
            return true;
        }

        /**
         * postRequest
         *
         * PostRequest is called after the request has completed.
         * returns true if succeeded
         * returns false is failed, and error is in errstr
         */
        public function postRequest(&$errstr)
        {
            ob_end_flush();
            $errstr = "";

            $duration = $this->nowMillis() - $this->requestStart;
            $code = $this->requestResponseCode();
            $size = $this->responseSize();
            $rid = $this->agentRequestID();
            // If a request ID exists, update the request
            if ($rid != "") {
                $msg = array(
                    "RequestID" => $rid,
                    "ResponseCode" => $code,
                    "ResponseMillis" => $duration,
                    "ResponseSize" => $size,
                    "HeadersOut" => $this->filterHeaders($this->responseHeaders()),
                );
                // Send the UpdateRequest to the Agent
                return $this->sendRPC("RPC.UpdateRequest", $msg, $errstr) !== false;
            }

            // Do logging for anamolies
            if (($code >= 300) || ($size >= $this->config['anomaly_size']) || ($duration >= $this->config['anomaly_duration'])) {
                $msg = $this->requestMsgCore();
                $msg['WAFResponse'] = $this->agentResponseCode();
                $msg['ResponseCode'] = $code;
                $msg['ResponseMillis'] = $duration;
                $msg['ResponseSize'] = $size;
                $msg['HeadersOut'] = $this->filterHeaders($this->responseHeaders());

                // Send the Post Request to the Agent
                return ($this->sendRPC("RPC.PostRequest", $msg, $errstr) !== false);
            }

            return true;
        }

        /**
         * responseCounter
         *
         * responseCounter is a callback for ob_start to capture the
         * number of bytes returned to the client.  See
         * http://php.net/manual/en/function.ob-start.php
         */
        protected function responseCounter($buf)
        {
            $this->responseSize += strlen($buf);
            // return false means return original buffer to client
            return false;
        }

        /**
         * send
         *
         * Sends the request payload to the agent
         */
        protected function send($sock, $data, &$errstr)
        {
            // Check if the socket is a resource
            if (!is_resource($sock)) {
                $errstr = "arg0 is not a socket!";
                return false;
            }

            $read_timeout = array("sec" => 0, "usec" => $this->config['read_timeout_microseconds']);
            $write_timeout = array("sec" => 0, "usec" => $this->config['write_timeout_microseconds']);

            // Silencing the next few socket actions since we catch response
            $success = (@socket_set_option($sock, SOL_SOCKET, SO_SNDTIMEO, $write_timeout) &&
                @socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, $read_timeout) &&
                @socket_connect($sock, $this->config['socket_address'], $this->config['socket_port']));
            if ($success === false) {
                $errorcode = socket_last_error();
                $errormsg = socket_strerror($errorcode);
                $errstr = "send:socket_connect: [$errorcode] $errormsg";
                return false;
            }

            // be safe and handle EAGAIN from socket
            $count = false;
            for ($i = 0; $i < 3; $i++) {
                $count = @socket_send($sock, $data, strlen($data), 0);
                if ($count !== false) {
                    break;
                }
                $errorcode = socket_last_error();
                if ($errorcode !== 11) {
                    $errormsg = socket_strerror($errorcode);
                    $errstr = "send:socket_send: xxx [$errorcode] $errormsg";
                    return false;
                }
            }
            if ($count === false) {
                $errorcode = socket_last_error();
                $errormsg = socket_strerror($errorcode);
                $errstr = "send:socket_send: oops [$errorcode] $errormsg";
                return false;
            }

            // try three times, to get a read
            //  sometimes the socket isn't quite ready
            //  and socket_read issues an EAGAIN error
            //
            for ($i = 0; $i < 3; $i++) {
                // response size is always well below 1k
                $resp = @socket_read($sock, 1024);
                if ($resp !== false) {
                    // got a good response, exit
                    break;
                }
                $errorcode = socket_last_error();
                if ($errorcode !== 11) {
                    // if not EAGAIN, then exit
                    $errormsg = socket_strerror($errorcode);
                    $errstr = "send:socket_read: xxx [$errorcode] $errormsg";
                    return false;
                }
            }

            // no nothing, EAGAIN loop failed, exit
            if ($resp === false) {
                $errormsg = socket_strerror($errorcode);
                $errstr = "send:socket_read: [$errorcode] $errormsg";
                return false;
            }
            return $resp;
        }

        public function validateRPCResponse($obj, &$errstr)
        {
            // validate that response is an RPC message
            if (!is_array($obj)) {
                $errstr = "Invalid MessagePack RPC response: not an object";
                return false;
            }
            if (count($obj) != 4) {
                $errstr = "Invalid MessagePack RPC response: expected count of 4, got " . count($obj);
                return false;
            }
            if ($obj[0] != 1) {
                $errstr = "Invalid MessagePack RPC response: expect obj[0] == 1, got " . print_r($obj[0], true);
                return false;
            }

            // valid but got error
            if ($obj[2] != null) {
                $errstr = "Error RPC Response: " . $obj[2];
                return false;
            }

            return true;
        }

        /**
         * sendRPC
         *
         * Formats the message to be sent to the agent
         */
        protected function sendRPC($method, $data, &$errstr)
        {
            $rpcmsg = array(
                0, /* magic number */
                0, /* counter, unused */
                $method,
                array($data),
            );
            $msg = msgpack_pack($rpcmsg);
            $errstr = "";

            $sock = @socket_create($this->config['socket_domain'], SOCK_STREAM, 0);
            if ($sock === false) {
                $errorcode = socket_last_error();
                $errormsg = socket_strerror($errorcode);
                $errstr = "sendRPC:socket_create: [$errorcode] $errormsg";
                return false;
            }
            $raw = $this->send($sock, $msg, $errstr);
            @socket_close($sock);

            if ($raw === false) {
                $errstr = "sendRPC:send: $errstr";
                return false;
            }

            try {
                $resp = msgpack_unpack($raw);
            } catch (Exception $e) {
                $errstr = "sendRPC:msgpack_unpack: " . $e;
                return false;
            }
            if (!$this->validateRPCResponse($resp, $errstr)) {
                return false;
            }
            // ok!
            return $resp[3];
        }

        /**
         * readPost
         *
         * Reads the POST body if it's a METHOD with
         * a post body
         */
        public function readPost()
        {
            if (!isset($this->cgi['REQUEST_METHOD']) || !in_array($this->cgi['REQUEST_METHOD'], $this->config['body_methods'])) {
                // no postbody if this isnt a POST or PUT or PATCH
                return false;
            }

            // No content length?
            if (!isset($this->cgi['CONTENT_LENGTH']) || !is_numeric($this->cgi['CONTENT_LENGTH'])) {
                return false;
            }

            // dont send very large post bodies for performance and sanity
            $clen = (int) $this->cgi['CONTENT_LENGTH'];
            if ($clen >= $this->config['max_post_size']) {
                return false;
            }

            // dont try to read negative length body
            // In PHP this doesnt make much different but on other platforms
            // on should validate that the value is numeric and non-negative.
            if ($clen < 0) {
                return false;
            }

            // typically present, but prevent notice errors
            if (!isset($this->cgi['CONTENT_TYPE'])) {
                return false;
            }
            $ctype = strtolower($this->cgi['CONTENT_TYPE']);
            if (strpos($ctype, "application/x-www-form-urlencoded") === false &&
                strncmp($ctype, "multipart/form-data", 19) != 0 &&
                strncmp($ctype, "application/graphql", 19) != 0 &&
                strpos($ctype, "json") === false &&
		strpos($ctype, "javascript") === false &&
	        strpos($ctype, "xml") === false) {
                return false;
            }

            return true;
        }

        protected function postData()
        {
            if (isset($HTTP_RAW_POST_DATA)) {
                return $HTTP_RAW_POST_DATA;
            }

            // php 5.6
            return file_get_contents("php://input");
        }

        /**
         * serverVersion
         *
         * return the version of the WebServer
         */
        public function serverVersion()
        {
            // otherwise it returns something werird
            if (function_exists('apache_get_version')) {
                return apache_get_version();
            }

            if (isset($this->cgi['SERVER_SOFTWARE'])) {
                return $this->cgi['SERVER_SOFTWARE'] . '/PHP' . PHP_VERSION;
            }

            return 'PHP' . PHP_VERSION . '/' . PHP_SAPI;
        }

        /**
         * requestResponseCode
         *
         * Returns the HTTP Response Code.
         * PHP >= 5.4
         * For PHP < 5.4, it return 0.  Subclass
         *  to fix with your platform.
         */
        public function requestResponseCode()
        {
            if (function_exists('http_response_code')) {
                return http_response_code();
            }
            return 0;
        }

        /**
         * responseSize
         *
         * Return Response Size in bytes.. not available in PHP/Apache
         */
        protected function responseSize()
        {
            return $this->responseSize;
        }

        /**
         * requestHeaders
         */
        protected function requestHeaders()
        {
            // Not apache specific if php >= 5.4
            if (!function_exists('apache_request_headers')) {
                return $this->requestHeaders53();
            }
            // actually works on non-apache servers if php >= 5.4
            $headers = apache_request_headers();
            if ($headers === false) {
                return array();
            }
            return $headers;
        }

        /**
         * requestHeader53 get the HTTP response headers sent in PHP 5.3
         *
         * exposed for testing
         * This could be a static function
         */
        public function requestHeaders53()
        {
            // set default for headers
            $headers = array();
            // for PHP < 5.4
            foreach ($this->cgi as $name => $value) {
                if (substr($name, 0, 5) == 'HTTP_') {
                    $name = substr($name, 5);
                    $name = str_replace('_', ' ', $name);
                    $name = strtolower($name);
                    $name = ucwords($name);
                    $name = str_replace(' ', '-', $name);
                    $headers[$name] = $value;
                }
            }

            return $headers;
        }

        /**
         * responseHeaders -- not clear what happens with
         *   apache_response_headers() in the case of multiple values
         *
         */
        protected function responseHeaders()
        {
            // Not apache specific if php >= 5.4
            if (!function_exists('apache_response_headers')) {
                return $this->responseHeaders53(headers_list());
            }
            $headers = apache_response_headers();

            $meta = $this->agentMeta();
            if (isset($meta['X-Sigsci-Redirect'])) {
                if ($this->agentResponseCode() <= 399)
                {
                    $headers['location'] = $meta['X-Sigsci-Redirect'];
                }
            }

            if ($headers === false) {
                return array();
            }
            return $headers;
        }

        public function responseHeaders53($headers)
        {
            // for PHP < 5.4
            $out = array();
            foreach ($headers as $header) {
                $header = explode(":", $header, 2);
                $out[$header[0]] = trim($header[1]);
            }

            $meta = $this->agentMeta();
            if (isset($meta['X-Sigsci-Redirect'])) {
                if ($this->agentResponseCode() <= 399)
                {
                    $out['location'] = $meta['X-Sigsci-Redirect'];
                }
            }
            return $out;
        }

        /**
         * scheme
         *
         * Scheme returns "https" if the connection is likely under TLS/SSL
         *  otherwise returns "http"
         *  See http://stackoverflow.com/questions/7304182/detecting-ssl-with-php
         *
         * http://php.net/manual/en/reserved.variables.server.php
         *
         * 'HTTPS' Set to a non-empty value if the script was queried
         * through the HTTPS protocol.  Note: Note that when using ISAPI
         * with IIS, the value will be off if the request was not made
         * through the HTTPS protocol.
         */
        public function requestScheme()
        {
            if (isset($this->cgi['HTTPS']) && (!empty($this->cgi['HTTPS'])) && ('off' !== strtolower($this->cgi['HTTPS']))) {
                return "https";
            }
            if (isset($this->cgi['SERVER_PORT']) && ('443' == $this->cgi['SERVER_PORT'])) {
                return "https";
            }
            return "http";
        }

        /**
         * filterHeaders
         *
         * It converts HTTP headers into an array of key/value pairs,
         */
        public function filterHeaders($ary)
        {
            $headers = array();
            foreach ($ary as $header => $value) {
                $name = strtolower($header);
                $headers[] = array($header, $value);
            }
            return $headers;
        }

        /**
         * override for testing, etc
         */
        protected function nowMillis()
        {
            return (int) (microtime(true) * 1000);
        }

        protected function requestMsgCore()
        {
            // Establish the Agent payload
            return array(
                "ModuleVersion" => $this->moduleVersion,
                "ServerVersion" => $this->serverVersion(),
                "ServerFlavor" => PHP_VERSION,
                "ServerName" => $this->cgi['SERVER_NAME'],
                "Timestamp" => (int) $this->cgi['REQUEST_TIME'],
                "NowMillis" => $this->nowMillis(),
                "RemoteAddr" => $this->cgi['REMOTE_ADDR'],
                "Method" => $this->cgi['REQUEST_METHOD'],
                "Scheme" => $this->requestScheme(),
                "URI" => $this->cgi['REQUEST_URI'],
                "Protocol" => $this->cgi['SERVER_PROTOCOL'],
                "HeadersIn" => $this->filterHeaders($this->requestHeaders()),
            );

            // TBD
            // "TLSProtocol" => "N/A", // We don't get this information in PHP
            // "TLSCipher" => "N/A", // We don't get this information in PHP
        }

        // exposed for testing
        public function setAgentResponse($obj)
        {
            $this->agentResponse = $obj;
        }
    } /* end class SigSciModule */;

}

/**
 * SigSciModuleSimple
 *
 * SigSciModuleSimple automates calling of pre and post-Request
 * and does primitive error logging.
 *
 */
class SigSciModuleSimple extends SigSciModule
{
    /**
     * __construct creates and immediately calls preRequest
     */
    public function __construct($config = array(), $server = null)
    {
        parent::__construct($config, $server);
        $errstr = "";
        if (false === parent::preRequest($errstr)) {
            error_log("SIGSCI: error in prerequest: " . $errstr);
        }
    }

    /**
     * __destruct calls post-request.
     *
     *  Make sure object is in-scope until end of request (i.e.a  global)
     */
    public function __destruct()
    {
        $errstr = "";
        if (false == parent::postRequest($errstr)) {
            error_log("SIGSCI: error in postrequest: " . $errstr);
        }
    }
}
