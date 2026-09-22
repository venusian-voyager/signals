<?php

if (! function_exists('signal')) {
    /**
     * Fire a signal and call its listeners.
     *
     * Pass a signal object, or a name plus a payload. With $halt, the answer is the first
     * non-null one a listener gave; otherwise it is every listener's answer, in order.
     *
     * @param string|object $signal
     * @param mixed $payload
     * @param bool $halt
     * @return mixed
     * @throws ReflectionException
     */
    function signal(string|object $signal, mixed $payload = [], bool $halt = false): mixed
    {
        return app('signals')->dispatch($signal, $payload, $halt);
    }
}