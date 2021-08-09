<?php

namespace Hbsolutions\Exceptions;

class ExcepcionesError
{

    private $e;

    public function __construct(\Error $e)
    {
        $this->e = $e;
    }

    public function formatEventException()
    {
        return [
            "message" => $this->e->getMessage(),
            "line" => $this->e->getLine(),
            "file" => $this->e->getFile(),
            "messageComplete" => $this->e->getTraceAsString(),
        ];
    }
}
