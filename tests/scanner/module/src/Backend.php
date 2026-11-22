<?php
declare(strict_types=1);

namespace Dotclear\Plugin\fixture;

use dcCore;
use Dotclear\Helper\Html\Form\Dd;
use Dotclear\Plugin\widgets\WidgetsElement;
use Dotclear\Core\Backend\Favorite;

class Backend extends Favorite
{
    public function init(): bool
    {
        $blog = dcCore::app()->blog;                     // alert class (import + static)
        $el   = Dd::DEFAULT_ELEMENT;                     // alert const
        $p    = \dcUtils::path(['a', 'b']);              // alert method of removed class
        App::behavior()->addBehaviors([
            'adminPageNotification' => $this->x(...),    // notice behavior
            'adminDashboardItemsV2' => $this->y(...),    // nothing
        ]);
        $this->largeIcon();                              // notice method via $this (Favorite::largeIcon)
        $w = new WidgetsElement('a', 'b', 'c');
        $w->setting('n', 't', 'v', 'text', []);          // notice method (not unique, unknown class: not found)

        return true;
    }

    public function largeIcon(): string                  // override of a deprecated method
    {
        return parent::largeIcon();                      // notice via parent::
    }
}
