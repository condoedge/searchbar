<?php

function compressArray($input = [])
{
    return base64_encode(gzcompress(json_encode($input)));
}

function uncompressArray(string $input)
{
    return json_decode(gzuncompress(base64_decode($input)), true);
}