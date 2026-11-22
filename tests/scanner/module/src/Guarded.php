<?php
namespace Dotclear\Plugin\fixture;

class Guarded
{
    public function old(): void
    {
        if (class_exists('dcCore')) {
            $x = \dcCore::app();                         // alert lowered to notice (guarded)
        }
    }
}
