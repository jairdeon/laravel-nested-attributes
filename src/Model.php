<?php

namespace Thomisticus\NestedAttributes;

use Illuminate\Database\Eloquent\Model as Eloquent;

abstract class Model extends Eloquent
{
    /*
     * @codingStandardsIgnoreFile
     * Make nested attributes for model.
     *
     * Nested attributes allow you to save attributes on associated records through the parent.
     * By default nested attribute updating is turned off and you can enable it using the $nested array.
     * When you enable nested attributes an attribute writer is defined on the model.
     *
     * @see Traits\HasNestedAttributes
     */
    use Traits\HasNestedAttributes;
}
