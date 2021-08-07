<?php
namespace Hbsolutions\Exceptions\HttpExceptions;

use Hbsolutions\Exceptions\BaseExceptions\BaseExceptions;


class HttpExceptions extends BaseExceptions {
    public function __construct(String $message, String $data, String $microservice, int $status = 404){
        parent::__construct($message,  $data,  $microservice,  $status);
    }

    public function obtenerMensaje(){
        return "si llega";
        
    }
}