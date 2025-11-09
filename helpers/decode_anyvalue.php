<?php

use OpenTelemetry\Proto\Common\V1\AnyValue;
use OpenTelemetry\Proto\Common\V1\ArrayValue;
use OpenTelemetry\Proto\Common\V1\KeyValueList;

if (! function_exists("decode_anyvalue")) {
    /**
     * Recursively decode an OpenTelemetry AnyValue into a PHP scalar/array.
     *
     * @param AnyValue $valObj
     * @return mixed
     */
    function decode_anyvalue(AnyValue $valObj)
    {
        if ($valObj->hasStringValue()) {
            return $valObj->getStringValue();
        }
        if ($valObj->hasIntValue()) {
            return $valObj->getIntValue();
        }
        if ($valObj->hasBoolValue()) {
            return $valObj->getBoolValue();
        }
        if ($valObj->hasDoubleValue()) {
            return $valObj->getDoubleValue();
        }
        if ($valObj->hasArrayValue()) {
            $result = [];
            /** @var ArrayValue $array */
            $array = $valObj->getArrayValue();
            foreach ($array->getValues() as $item) {
                $result[] = decode_anyvalue($item);
            }
            return $result;
        }
        if ($valObj->hasKvlistValue()) {
            $result = [];
            /** @var KeyValueList $kvlist */
            $kvlist = $valObj->getKvlistValue();
            foreach ($kvlist->getValues() as $kv) {
                $result[$kv->getKey()] = decode_anyvalue($kv->getValue());
            }
            return $result;
        }

        // Unknown / unset type
        return null;
    }
    }