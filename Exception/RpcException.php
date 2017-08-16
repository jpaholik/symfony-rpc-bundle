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

namespace Seven\RpcBundle\Exception;

use Exception;

class RpcException extends Exception
{
    private $data;

    /**
     * Set additional information about the error.
     *
     * @param mixed $data
     */
    public function setData($data)
    {
        $this->data = $data;
    }

    /**
     * Get additional information about the error.
     *
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }
}
