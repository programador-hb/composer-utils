<?php
namespace Hbsolutions\Exceptions;

// class ExcepetionsHbsolutions extends \Exception{

//     public static function exceptionFormat(\Exception $e){

//         return [
//             "message" => $e->getMessage(),
//             "line" => $e->getLine(),
//             "file" => $e->getFile(),
//             "messageComplete" => $e->getTraceAsString()
//         ];
//     }
// }



class BaseExceptions extends \Exception {

    protected $message;
    protected $data;
    protected $microservice;

    public function __construct(String $message, String $data, String $microservice, int $status = 404 ){
        $this->message = $message;
        $this->data = $data;
        $this->microservice = $microservice;
        $this->status = $status;

        $data = [
            "message" => $this->message,
            "data" => $this->data,
            "microservice" => $this->microservice,
            "status" => $this->status,
        ];

        // Model::create($data);
    }

}


class HttpExceptions extends BaseExceptions {
    public function __construct(String $message, String $data, String $microservice, int $status = 404){
        parent::__construct($message,  $data,  $microservice,  $status = 404);
    }

    public function obtenerMensaje(){
        return "si llega";
    }
}