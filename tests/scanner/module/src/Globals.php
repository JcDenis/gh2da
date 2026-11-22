<?php
namespace Dotclear\Plugin\fixture;

class Globals
{
    public function old(): string
    {
        // global $_ctx;                                 nothing: comment
        /* $GLOBALS['_ctx'] */
        global $_ctx;                                    // notice: forecast (2.45 rule, next is 2.41)
        $x = $GLOBALS['_ctx'];                           // alert (2.41 rule)

        return 'global $_ctx;';                          // notice: forecast (strings are code)
    }
}
