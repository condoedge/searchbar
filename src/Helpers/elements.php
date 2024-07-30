<?php

function _RulePill($key, $valueEl = null, $icon = null)
{
    return _Flex(
        _Html($key)->when($icon, fn($el) => $el->icon($icon))->class('text-sm bg-level1 text-white px-2 p-1 rounded-l-md'),

        !$valueEl ? null : $valueEl->class('text-sm bg-level4 px-2 p-1 rounded-r-md'),
    )->class('rounded-md w-max overflow-hidden');
}