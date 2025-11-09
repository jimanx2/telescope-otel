<?php

if (! function_exists("str_replace_sub")) {
    function str_replace_sub($input, $sub = '\?', $replaces) {
        $index = 0;
        return preg_replace_callback("/($sub)+/", function($matches) use ($replaces, &$index) {
            return $replaces[$index++];
        }, $arr);
    }
}