<?php

use Illuminate\Support\Arr;

if (! function_exists("arr_sole_by_key")) {
    function arr_sole_by_key($arr, $searchKeys, $default = null)
    {
        $arr = Arr::undot($arr);
        foreach($searchKeys as $key)
        {
            if (Arr::has($arr, $key)) {
                return Arr::get($arr, $key);
            }
        }
        return $default;
    } 
}