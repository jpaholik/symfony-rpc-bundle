<?php

/*
 * This file is part of the Symfony bundle Seven/Rpc.
 *
 * (c) Sergey Kolodyazhnyy <sergey.kolodyazhnyy@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */

namespace Seven\RpcBundle\Rpc;

use Exception;
use Psr\Log\LoggerInterface;
use Seven\RpcBundle\Exception\InvalidParameters;
use Seven\RpcBundle\Exception\MethodNotExists;
use Seven\RpcBundle\Rpc\Method\MethodCall;
use Seven\RpcBundle\Rpc\Method\MethodFault;
use Seven\RpcBundle\Rpc\Method\MethodResponse;
use Seven\RpcBundle\Rpc\Method\MethodReturn;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class Server implements ServerInterface
{
    const HTTP_SUCCESS_STATUS = 200;

    protected $impl;
    protected $handlers;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param Implementation $impl
     */
    public function __construct(Implementation $impl)
    {
        $this->impl = $impl;
    }

    public function getLogger()
    {
        return $this->logger;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param Request $request
     *
     * @return Response
     */
    public function handle(Request $request)
    {
        try {
            $methodCall = $this->impl->createMethodCall($request);
            $methodResponse = $this->_handle($methodCall);
            $exceptionStatus = self::HTTP_SUCCESS_STATUS;
        } catch (\Exception $e) {

            // log exception
            if (null !== $this->logger) {
                $this->logger->error($e);
            }

            $methodResponse = new MethodFault($e);
            $exceptionStatus = method_exists($e, 'getHttpStatusCode') ? $e->getHttpStatusCode() : Implementation::BAD_REQUEST_STATUS_CODE;
        }
        return $this->impl->createHttpResponse($methodResponse, $exceptionStatus);
    }

    /**
     * @param MethodCall $methodCall
     *
     * @return MethodResponse
     * @throws MethodNotExists
     * @throws InvalidParameters
     */
    protected function _handle(MethodCall $methodCall)
    {
        $response = $this->call($methodCall->getMethodName(), $methodCall->getParameters());

        if (!($response instanceof MethodResponse)) {
            $response = new MethodReturn($response);
        }

        return $response;
    }

    /**
     * @param $method
     * @param $parameters
     *
     * @throws MethodNotExists
     *
     * @return mixed
     * @throws InvalidParameters
     */
    public function call($method, $parameters)
    {
        if ($this->hasHandler($method) && is_callable($callback = $this->getHandler($method))) {
            return $this->_call($callback, $this->prepareParameters($callback, $parameters));
        } elseif (strpos($method, '.') !== false) {
            list($handlerName, $methodName) = explode('.', $method, 2);
            if ($this->hasHandler($handlerName)) {
                if (is_callable($callback = array($this->getHandler($handlerName), $methodName))) {
                    return $this->_call($callback, $this->prepareParameters($callback, $parameters));
                }
            }
        }
        throw new MethodNotExists("Method '{$method}' is not defined");
    }

    /**
     * Returns true if variable is an associative array.
     *
     * @param array $arr
     *
     * @return bool
     */
    protected function isAssociative(array $arr)
    {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * Prepare parameters.
     *
     * @param callable $callback
     * @param array $parameters
     *
     * @return array
     *
     * @throws InvalidParameters
     */
    protected function prepareParameters($callback, array $parameters)
    {
        if (is_array($callback)) {
            $refl = new \ReflectionMethod($callback[0], $callback[1]);
        } else {
            $refl = new \ReflectionFunction($callback);
        }

        $reflParams = $refl->getParameters();

        $paramCount = count($parameters);
        if (!($paramCount >= $refl->getNumberOfRequiredParameters()
            && $paramCount <= $refl->getNumberOfParameters())
        ) {
            throw new InvalidParameters(
                sprintf('Invalid number of parameters. %d given but %d are required of %d total.',
                    $paramCount, $refl->getNumberOfRequiredParameters(), $refl->getNumberOfParameters())
            );
        }

        if (!$this->isAssociative($parameters)) {
            return $parameters;
        }

        // convert underscore and dash parameters to camelCase naming conventions
        $psrParams = array();
        foreach ($parameters as $key => $value) {
            $key = lcfirst(str_replace(' ', '', ucwords(strtr($key, array('_' => ' ', '-' => ' ')))));
            $psrParams[$key] = $value;
        }

        // map named parameters in correct order
        $newParams = array();
        foreach ($reflParams as $reflParam) {
            /* @var $reflParam \ReflectionParameter */
            $name = $reflParam->name;
            if (!isset($psrParams[$reflParam->name]) && !$reflParam->isOptional()) {
                throw new InvalidParameters("Parameter '{$name}' is missing.");
            }
            if (isset($psrParams[$reflParam->name])) {
                $newParams[] = $psrParams[$reflParam->name];
            } else {
                $newParams[] = null;
            }
        }

        return $newParams;
    }

    /**
     * @param $callback
     * @param $parameters
     *
     * @return mixed
     */
    protected function _call($callback, $parameters)
    {
        return call_user_func_array($callback, $parameters);
    }

    /**
     * @param $name
     * @param $handler
     * @param bool $force
     *
     * @throws Exception
     *
     * @return Server
     */
    public function addHandler($name, $handler, $force = false)
    {
        if (isset($this->handlers[$name]) && !$force) {
            throw new Exception("The '{$name}' handler already exists");
        }
        $this->handlers[$name] = $handler;

        return $this;
    }

    /**
     * @param $name
     *
     * @return mixed
     */
    public function hasHandler($name)
    {
        return isset($this->handlers[$name]);
    }

    /**
     * @param $name
     *
     * @return bool
     */
    public function getHandler($name)
    {
        if (!$this->hasHandler($name)) {
            return false;
        }
        if (is_string($this->handlers[$name])) {
            $this->handlers[$name] = new $this->handlers[$name]();
        }

        return $this->handlers[$name];
    }

    /**
     * @param $name
     *
     * @return Server
     */
    public function removeHandler($name)
    {
        unset($this->handlers[$name]);

        return $this;
    }
}
