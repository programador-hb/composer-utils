<?php
namespace Hbsolutions;

class Exceptions {

    public static function exceptionMessage(\Exception $e){
        return [
            "message" => $e->getMessage(),
            "line" => $e->getLine(),
            "file" => $e->getFile(),
            "messageComplete" => $e->getTraceAsString()
        ];
    }
}
