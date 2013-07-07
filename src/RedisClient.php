<?php
/**
 * This file is distributed under the MIT Open Source
 * License. See README.MD for details.
 * @author Ioan CHIRIAC
 */

defined('CRLF') OR define('CRLF', "\r\n");

class ClientError extends \Exception
{
    const TYPE = __CLASS__;
}

class ClientConnectionError extends ClientError
{
    const TYPE = __CLASS__;
}

class ClientIOError extends ClientError
{
    const TYPE = __CLASS__;
}

class ClientRedisError extends ClientError
{
    const TYPE = __CLASS__;
}

/**
 * A light Redis client
 * <code>
 *  $client = new RedisClient();
 *  $response = $client
 *      ->set('increment', 10)
 *      ->incr('increment')
 *      ->read();
 *  var_dump($respose); // array( 0 => true, 1 => 11 );
 * </code>
 */
class RedisClient
{

    protected $_socket;
    protected $_responses = 0;
    protected $_stack = array();

    /**
     * Initialize a redis connection
     * @param string $dsn
     * @throws ClientConnectionError
     */
    public function __construct($dsn = 'tcp://localhost:6379', $db = 0, $auth = null)
    {
        $code = null;
        $error = null;
        $this->_socket = stream_socket_client(
            $dsn, $code, $error, 1, STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT
        );
        if ($this->_socket === false) {
            $this->onConnectionError(
                $error, $code
            );
        }
        if (!empty($auth)) {
            $this->__call('AUTH', array($auth));
        }
        $this->__call('SELECT', array($db));
        try {
            $this->read();
        } catch( ClientError $error ) {
            $this->onConnectionError(
                'Unable to connect : ' . $error->getMessage()
            );
        }
    }

    /**
     * Raise an connection error
     * @param string $error
     * @param int $code
     * @throws ClientConnectionError
     */
    protected function onConnectionError($error, $code = 0)
    {
        if ($this->_socket) {
            fclose($this->_socket);
        }
        $this->onError(ClientConnectionError::TYPE, $error, $code);
    }

    /**
     * Raise an client error
     * @param string $error
     * @throws ClientError
     */
    protected function onClientError($error)
    {
        $this->onError(ClientError::TYPE, $error);
    }

    /**
     * Raise an Redis error
     * @param string $error
     * @throws ClientRedisError
     */
    protected function onClientRedisError($error)
    {
        $this->onError(ClientRedisError::TYPE, $error);
    }

    /**
     * Raise an client IO error
     * @param string $error
     * @throws ClientIOError
     */
    protected function onClientIOError($error)
    {
        if ($this->_socket) {
            fclose($this->_socket);
        }
        $this->onError(ClientIOError::TYPE, $error);
    }

    /**
     * Raise an error
     * @param string $type
     * @param string $error
     * @param int $code
     * @throws $type
     */
    protected function onError($type, $error, $code = 0)
    {
        throw new $type($error, $code);
    }

    /**
     * Sets field in the hash stored at key to value. If key does not exist,
     * a new key holding a hash is created. If field already exists in the
     * hash, it is overwritten.
     *
     * @param string $key
     * @param string|array $field
     * @param string $value
     * @return \redis\orm\Client
     * @link http://redis.io/commands/hset
     * @link http://redis.io/commands/hmset
     */
    public function hset($key, $field, $value = null)
    {
        if (is_array($field)) {
            $args = array($key);
            foreach( $field as $key => $value ) {
              $args[] = $key;
              $args[] = $value;
            }
            return $this->__call('HMSET', $args);
        } else {
            return $this->__call('HSET', array($key, $field, $value));
        }
    }

    public function hget($key, $field)
    {
        if (is_array($field)) {
            array_unshift($field, $key);
            return $this->__call('HMGET', $field);
        } else {
            return $this->__call('HGET', array($key, $field));
        }
    }

    /**
     * Increments the number stored at field in the hash stored at key by
     * increment. If key does not exist, a new key holding a hash is created.
     * If field does not exist the value is set to 0 before the operation
     * is performed.
     *
     * The range of values supported by HINCRBY is limited to 64 bit
     * signed integers.
     *
     * @param string $key
     * @param string $field
     * @param int $value
     * @return \redis\orm\Client
     * @link http://redis.io/commands/hincrby
     */
    public function hincr($key, $field, $value = 1)
    {
        return $this->__call('HINCRBY', array($key, $field, $value));
    }

    /**
     * Reads the redis response
     * @return mixed
     * @throws ClientError
     */
    public function read()
    {
        if (!empty($this->_stack))
            $this->flush();
        if ($this->_responses === 0) {
            $this->onClientError(
                'No pending response found'
            );
        }
        if ($this->_responses === 1) {
            $response = $this->_read();
        } else {
            $response = new \SplFixedArray($this->_responses);
            for ($i = 0; $i < $this->_responses; $i++) {
                $response->offsetSet($i, $this->_read());
            }
        }
        $this->_responses = 0;
        return $response;
    }

    /**
     * Flushing pending requests
     * @return \redis\orm\Client
     * @throws ClientError
     * @throws ClientIOError
     */
    public function flush()
    {
        $size = count($this->_stack);
        if ($size === 0) {
            $this->onClientError(
                'No pending requests'
            );
        }
        $this->_responses += $size;
        $buffer = implode(null, $this->_stack);
        $this->_stack = array();
        $blen = strlen($buffer);
        $fwrite = null;
        for ($written = 0; $written < $blen; $written += $fwrite) {
            $fwrite = fwrite($this->_socket, substr($buffer, $written));
            if ($fwrite === false || $fwrite <= 0) {
                $this->onClientIOError('Failed to write entire command to stream');
            }
        }
        return $this;
    }

    /**
     * Run a redis command
     * @param string $method
     * @param array $args
     * @return \redis\orm\Client
     */
    public function __call($method, $args)
    {
        $this->_stack[] = $this->_cmd($method, $args);
        return $this;
    }

    /**
     * Sends the specified command to Redis
     * @param type $command
     * @return \redis\orm\Client
     * @throws ClientIOError
     */
    protected function _write($command)
    {
        for ($written = 0; $written < strlen($command); $written += $fwrite) {
            $fwrite = fwrite($this->_socket, substr($command, $written));
            if ($fwrite === FALSE || $fwrite <= 0) {
                $this->onClientIOError(
                    'Failed to write entire command to stream'
                );
            }
        }
        return $this;
    }

    /**
     * Reads the redis pending response
     * @return string|integer|boolean|array
     * @throws ClientIOError
     * @throws ClientRedisError
     */
    protected function _read()
    {
        $reply = trim(fgets($this->_socket, 512));
        if ($reply === false) {
            $this->onClientIOError(
                'Network error - unable to read header response'
            );
        }
        switch ($reply[0]) {
            case '+': // inline reply
                $reply = substr($reply, 1);
                return ( strcasecmp($reply, 'OK') === 0 ) ?
                    true : $reply
                ;
                break;
            case '-': // error
                $this->onClientRedisError(trim(substr($reply, 4)));
                return false;
                break;
            case ':': // inline numeric
                return intval(substr($reply, 1));
                break;
            case '$': // bulk reply
                $size = intval(substr($reply, 1));
                if ($size === -1)
                    return null;
                $reply = '';
                if ($size > 0) {
                    $read = 0;
                    do {
                        $block_size = ($size - $read) > 1024 ? 1024 : ($size - $read);
                        $r = fread($this->_socket, $block_size);
                        if ($r === FALSE) {
                            $this->onClientIOError(
                                'Failed to read bulk response from stream'
                            );
                        } else {
                            $read += strlen($r);
                            $reply .= $r;
                        }
                    } while ($read < $size);
                }
                fread($this->_socket, 2); /* discard crlf */
                return $reply;
                break;
            case '*': // multi-bulk reply
                $size = intval(substr($reply, 1));
                if ($size === -1)
                    return null;
                if ($size === 0)
                    return array();
                $reply = new \SplFixedArray($size);
                for ($i = 0; $i < $size; $i++) {
                    $reply->offsetSet(
                        $i, $this->_read()
                    );
                }
                return $reply;
                break;
        }
        $this->onClientRedisError(
            'Undefined protocol response type : ' . $reply
        );
    }

    /**
     * Builds the specified command
     * @param string $method
     * @param array $args
     * @return string
     */
    protected function _cmd($method, $args)
    {
        $response =
            '*' . (count($args) + 1) . CRLF
            . '$' . strlen($method) . CRLF
            . strtoupper($method);
        foreach ($args as $k => $arg) {
            if ( !is_numeric($k) ) {
                $response .= CRLF . '$' . strlen($k) . CRLF . $k;
            }
            $response .= CRLF . '$' . strlen($arg) . CRLF . $arg;
        }
        return $response . CRLF;
    }

}