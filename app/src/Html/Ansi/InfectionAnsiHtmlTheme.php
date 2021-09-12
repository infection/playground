<?php

declare(strict_types=1);

namespace App\Html\Ansi;

final class InfectionAnsiHtmlTheme extends Theme
{
    public function asArray()
    {
        return [
            'black' => 'black',
            'red' => 'darkred',
            'green' => 'green',
            'yellow' => 'orange',
            'blue' => 'blue',
            'magenta' => 'darkmagenta',
            'cyan' => 'cyan',
            'white' => 'white',
            'brblack' => 'black',
            'brred' => 'red',
            'brgreen' => 'green',
            'bryellow' => 'lightyellow',
            'brblue' => 'blue',
            'brmagenta' => 'magenta',
            'brcyan' => 'lightcyan',
            'brwhite' => 'white',
            '#ef7373' => '#ef7373',
        ];
    }
}
