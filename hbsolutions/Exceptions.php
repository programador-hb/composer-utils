<?php
namespace Hbsolutions;

class ExcepetionsHbsolutions extends \Exception{

    public static function exceptionFormat(\Exception $e){

        return [
            "message" => $e->getMessage(),
            "line" => $e->getLine(),
            "file" => $e->getFile(),
            "messageComplete" => $e->getTraceAsString()
        ];
    }
}



class HttpExceptions extends \Exception {

    private $message;
    private $data;
    private $microservice;

    public function __construct(String $message, String $data, String $microservice ){
        $this->message = $message;
        $this->data = $data;
        $this->microservice = $microservice;
    }


    public function obtenerMensaje(){
        return [
            "message" => $this->message,
            "data" => $this->data,
            "microservice" => $this->microservice,
        ];
    }




}
