<?php
namespace Dotclear\Plugin\fixture;

class Third
{
    public function run($obj): void
    {
        $obj->anotherOldMethod();                        // nothing: the module declares a method with this name (Other)
    }
}
