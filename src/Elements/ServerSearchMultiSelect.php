<?php

namespace Kompo\Searchbar\Elements;

use Kompo\MultiSelect;

/** A multi-select searching its options on the server as the user types (FilterableColumnTypeEnum::selectElement()). */
class ServerSearchMultiSelect extends MultiSelect
{
    use KeepsBuiltOptions;
}
