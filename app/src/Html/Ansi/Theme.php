<?php

declare(strict_types=1);


namespace App\Html\Ansi;


/**
 * Base theme.
 */
class Theme
{
    /**
     * @var string|null
     */
    protected $prefix;

    public function __construct($prefix = null)
    {
        $this->prefix = $prefix;
    }

    public function asCss()
    {
        $css = [];
        foreach ($this->asArray() as $name => $color) {
            $css[] = sprintf('.%s_fg_%s { color: %s }', $this->prefix, $name, $color);
            $css[] = sprintf('.%s_bg_%s { background-color: %s }', $this->prefix, $name, $color);
        }
        $css[] = sprintf('.%s_underlined { text-decoration: underlined }', $this->prefix);

        return implode("\n", $css);
    }

    public function asArray()
    {
        return [
            'black'     => 'black',
            'red'       => 'darkred',
            'green'     => 'green',
            'yellow'    => 'yellow',
            'blue'      => 'blue',
            'magenta'   => 'darkmagenta',
            'cyan'      => 'cyan',
            'white'     => 'white',
            'brblack'   => 'black',
            'brred'     => 'red',
            'brgreen'   => 'lightgreen',
            'bryellow'  => 'lightyellow',
            'brblue'    => 'lightblue',
            'brmagenta' => 'magenta',
            'brcyan'    => 'lightcyan',
            'brwhite'   => 'white',
        ];
    }

    /**
     * @return string|null
     */
    public function getPrefix()
    {
        return $this->prefix;
    }

    /**
     * @param string $prefix
     */
    public function setPrefix($prefix)
    {
        $this->prefix = $prefix;
    }
}
