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
use Seven\RpcBundle\Exception\MethodNotExists;
use Seven\RpcBundle\Rpc\Method\MethodReturn;
use Seven\RpcBundle\Rpc\Method\MethodFault;
use Seven\RpcBundle\Rpc\Method\MethodCall;
use Seven\RpcBundle\Rpc\Method\MethodResponse;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Seven\RpcBundle\Exception\InvalidParameters;

class Server implements ServerInterface
{
    protected $impl;
    protected $handlers;

    /**
     * @param Implementation $impl
     */

    public function __construct(Implementation $impl)
    {
        $this->impl = $impl;
    }

    /**
     * @param  Request  $request
     * @return Response
     */

    public function handle(Request $request)
    {
        try {
            $methodCall = $this->impl->createMethodCall($request);
            $methodResponse = $this->_handle($methodCall);
        } catch (\Exception $e) {
            $methodResponse = new MethodFault($e);
        }

        return $this->impl->createHttpResponse($methodResponse);
    }

    /**
     * @param  MethodCall     $methodCall
     * @return MethodResponse
     */

    protected function _handle(MethodCall $methodCall)
    {
        $response = $this->call($methodCall->getMethodName(), $methodCall->getParameters());

        if(!($response instanceof MethodResponse))
            $response = new MethodReturn($response);

        return $response;
    }

    /**
     * @param $method
     * @param $parameters
     * @throws MethodNotExists
     * @return mixed
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

        $newParams = array();
        foreach ($reflParams as $reflParam) {
            /* @var $reflParam \ReflectionParameter */
            $name = $reflParam->name;
            if (!isset($parameters[$reflParam->name]) && !$reflParam->isOptional()) {
                throw new InvalidParameters("Parameter '{$name}' is missing.");
            }
            if (isset($parameters[$reflParam->name])) {
                $newParams[] = $parameters[$reflParam->name];
            } else {
                $newParams[] = null;
            }
        }

        return $newParams;
    }

    /**
     * @param $callback
     * @param $parameters
     * @return mixed
     */

    protected function _call($callback, $parameters)
    {
        return call_user_func_array($callback, $parameters);
    }

    /**
     * @param $name
     * @param $handler
     * @param  bool      $force
     * @throws Exception
     * @return Server
     */

    public function addHandler($name, $handler, $force = false)
    {
        if(isset($this->handlers[$name]) && !$force)
            throw new Exception("The '{$name}' handler already exists");
        $this->handlers[$name] = $handler;

        return $this;
    }

    /**
     * @param $name
     * @return mixed
     */

    public function hasHandler($name)
    {
        return isset($this->handlers[$name]);
    }

    /**
     * @param $name
     * @return bool
     */

    public function getHandler($name)
    {
        if (!$this->hasHandler($name)) {
            return false;
        }
        if (is_string($this->handlers[$name])) {
            $this->handlers[$name] = new $this->handlers[$name];
        }

        return $this->handlers[$name];
    }

    /**
     * @param $name
     * @return Server
     */

    public function removeHandler($name)
    {
        unset($this->handlers[$name]);

        return $this;
    }

}
