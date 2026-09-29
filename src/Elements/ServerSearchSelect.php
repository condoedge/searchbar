<?php

namespace Kompo\Searchbar\Elements;

use Kompo\Select;

/** A select searching its options on the server as the user types (FilterableColumnTypeEnum::selectElement()). */
class ServerSearchSelect extends Select
{
    use KeepsBuiltOptions;
}
