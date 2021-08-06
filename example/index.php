<?php

include __DIR__ . '/../vendor/autoload.php';

use Hbsolutions\Exceptions\HttpExceptions;


try {
    throw new Exception('Este es el mensaje');
} catch (HttpExceptions $e) {
    var_dump($e->obtenerMensaje());
}catch(Exception $e){
    echo "Llega aqui";
}