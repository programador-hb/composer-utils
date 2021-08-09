<?php

namespace Hbsolutions\Exceptions;

class Excepciones extends \Exception
{

    private $e;

    public function __construct(\Exception $e)
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

    public function testing(){

    }
}
