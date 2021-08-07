<?php

namespace Hbsolutions\Exceptions;

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