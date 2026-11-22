<?php
namespace Dotclear\Plugin\fixture;

use function Dotclear\Helper\oldFunction;
use Dotclear\Test\Applied;

class Other
{
    public function run($obj): void
    {
        $obj->veryOldMethod();                           // alert, probable (unique method name)
        $obj?->veryOldMethod();                          // alert, probable
        oldFunction('x');                                // alert function (imported)
        \Dotclear\Helper\oldFunction('y');               // alert function (fully qualified)
        $a = new Applied();                              // nothing: rule already applied to 2.40.1
        $f = function () use ($obj) { return $obj; };    // closure use: nothing
        $this->veryOldMethod();                          // nothing: a method of this class
        $s = 'form';                                     // nothing: plain word
    }

    private function anotherOldMethod(): void            // declared by the module
    {
    }
}
