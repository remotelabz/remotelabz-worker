<?php

namespace App\Bridge\Tools;

class ArrayTools
{
    public static function arrayFilterEmpty(array $array)
    {
        $result = array_filter($array, function($v){
            return trim($v);
        });

        $res = array_slice($result, 0);

        return $res;
    }
}