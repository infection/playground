<?php

declare(strict_types=1);

namespace App\Html\Ansi;

use voku\AnsiConverter\Theme\Theme;

/**
 * Converts an ANSI text to HTML5.
 */
final class AnsiToHtmlConverter
{
    /**
     * @var Theme
     */
    private $theme;

    /**
     * @var string
     */
    private $charset;

    /**
     * @var bool
     */
    private $inlineStyles;

    /**
     * @var bool
     */
    private $invertBackground;

    /**
     * @var string[]
     */
    private $inlineColors;

    /**
     * @var string[]
     */
    private $colorNames;

    /**
     * @var string
     */
    private $cssPrefix;

    /**
     * @param bool       $inlineStyles
     * @param string     $charset
     * @param string     $cssPrefix
     */
    public function __construct(Theme $theme = null, $inlineStyles = true, $charset = 'UTF-8', $cssPrefix = 'ansi_color')
    {
        $this->setTheme($theme, $cssPrefix);
        $this->setInlineStyles($inlineStyles);
        $this->setCharset($charset);
        $this->setColors();
    }

    /**
     * @param $text
     *
     * @return string
     */
    public function convert($text)
    {
        // remove cursor movement sequences
        $text = preg_replace("#\e\[(?:K|s|u|2J|2K|\d+(?:[ABCDEFGJKST])|\d+;\d+(?:[Hf]))#", '', $text);
        $text = htmlspecialchars($text, ENT_COMPAT | ENT_SUBSTITUTE, $this->charset);

        // carriage return
        $text = str_replace("\r\n", "\n", $text);
        $text = preg_replace("#^(?:.*\r)#s", '', $text);

        $tokens = $this->tokenize($text);

        // a backspace remove the previous character but only from a text token
        foreach ($tokens as $i => $token) {
            if ('backspace' === $token[0]) {
                $j = $i;
                while (--$j >= 0) {
                    if (
              'text' === $tokens[$j][0]
              &&
              \strlen($tokens[$j][1]) > 0
          ) {
                        $tokens[$j][1] = substr($tokens[$j][1], 0, -1);

                        break;
                    }
                }
            }
        }

        // init
        $html = '';

        foreach ($tokens as $token) {
            if ('text' === $token[0]) {
                $html .= $token[1];
            } elseif ('color' === $token[0]) {
                $html .= $this->convertAnsiToColor($token[1]);
            }
        }

        if ($this->invertBackground) {
            if ($this->inlineStyles) {
                $html = sprintf('<span style="background-color: %s; color: %s;">%s</span>', $this->inlineColors['white'], $this->inlineColors['black'], $html);
            } else {
                $html = sprintf('<span class="%1$s_bg_white %1$s_fg_black">%2$s</span>', $this->cssPrefix, $html);
            }
        } else {
            if ($this->inlineStyles) {
                $html = sprintf('<span style="background-color: %s; color: %s;">%s</span>', $this->inlineColors['black'], $this->inlineColors['white'], $html);
            } else {
                $html = sprintf('<span class="%1$s_bg_black %1$s_fg_white">%2$s</span>', $this->cssPrefix, $html);
            }
        }

        // remove empty span
        $html = preg_replace('#<span[^>]*></span[^>]*>#', '', $html);

        return (string) $html;
    }

    /**
     * @return Theme
     */
    public function getTheme()
    {
        return $this->theme;
    }

    /**
     * @param string     $cssPrefix
     */
    public function setTheme(Theme $theme = null, $cssPrefix = 'ansi_color')
    {
        if ($theme === null) {
            // If no theme supplied create one and use the default css prefix.
            $this->theme = new Theme($cssPrefix);
            $this->cssPrefix = $cssPrefix;
        } else {
            // Use the supplied theme and the themes prefix if it is defined.
            $this->theme = $theme;
            $this->cssPrefix = $theme->getPrefix();
            if ($this->cssPrefix === null) {
                // Set the prefix on the theme and use the prefix locally.
                $this->theme->setPrefix($cssPrefix);
                $this->cssPrefix = $cssPrefix;
            }
        }

        $this->inlineColors = $this->theme->asArray();
    }

    /**
     * @return bool
     */
    public function isInvertBackground()
    {
        return $this->invertBackground;
    }

    /**
     * @param bool $invertBackground
     */
    public function setInvertBackground($invertBackground)
    {
        $this->invertBackground = (bool) $invertBackground;

        $this->setColors();
    }

    /**
     * @return bool
     */
    public function isInlineStyles()
    {
        return $this->inlineStyles;
    }

    /**
     * @param bool $inlineStyles
     */
    public function setInlineStyles($inlineStyles)
    {
        $this->inlineStyles = (bool) $inlineStyles;
    }

    /**
     * @return string
     */
    public function getCharset()
    {
        return $this->charset;
    }

    /**
     * @param string $charset
     */
    public function setCharset($charset)
    {
        $this->charset = $charset;
    }

    private function tokenize($text)
    {
        // init
        $tokens = [];

        preg_match_all("/(?:\e\[(.*?)m|(\x08))/", $text, $matches, PREG_OFFSET_CAPTURE);

        $offset = 0;
        foreach ($matches[0] as $i => $match) {
            if ($match[1] - $offset > 0) {
                $tokens[] = ['text', substr($text, $offset, $match[1] - $offset)];
            }
            $tokens[] = ["\x08" === $match[0] ? 'backspace' : 'color', $matches[1][$i][0]];
            $offset = $match[1] + \strlen($match[0]);
        }
        if ($offset < \strlen($text)) {
            $tokens[] = ['text', substr($text, $offset)];
        }

        return $tokens;
    }

    private function convertAnsiToColor($ansi)
    {
        $bg = 0;
        $fg = 7;
        $as = '';
        if ('0' !== $ansi && '' !== $ansi) {
            $options = explode(';', $ansi);

            foreach ($options as $option) {
                if ($option >= 30 && $option < 38) {
                    $fg = $option - 30;
                } elseif ($option >= 40 && $option < 48) {
                    $bg = $option - 40;
                } elseif (39 === $option) {
                    $fg = 7;
                } elseif (49 === $option) {
                    $bg = 0;
                }
            }

            // options: bold => 1, underscore => 4, blink => 5, reverse => 7, conceal => 8

            if (\in_array('1', $options, true)) {
                $fg += 10;
                $bg += 10;
            }

            if (\in_array('4', $options, true)) {
                $as = '; text-decoration: underline';
            }

            if (\in_array('7', $options, true)) {
                $tmp = $fg;
                $fg = $bg;
                $bg = $tmp;
            }
        }

        if ($this->inlineStyles) {
            $bgColor = $this->inlineColors[$this->colorNames[$bg]];
            $fgColor = $this->inlineColors[$this->colorNames[$fg]];

            if ($this->colorNames[$bg] === 'red' && $this->colorNames[$fg] === 'black') {
                $bgColor = 'darkred';
                $fgColor = 'white';
            }

            return sprintf(
                '</span><span style="background-color: %s; color: %s%s;">',
                $bgColor,
                $fgColor,
                $as
            );
        }

        return sprintf(
            '</span><span class="%1$s_bg_%2$s %1$s_fg_%3$s%4$s">',
            $this->cssPrefix,
            $this->colorNames[$bg],
            $this->colorNames[$fg],
            ($as ? sprintf(' %1$s_underlined', $this->cssPrefix) : '')
        );
    }

    private function setColors()
    {
        if ($this->invertBackground) {
            $this->colorNames = [
          'white',
          'red',
          'green',
          'yellow',
          'blue',
          'magenta',
          'cyan',
          'black',
          '',
          '',
          'brwhite',
          'brred',
          'brgreen',
          'bryellow',
          'brblue',
          'brmagenta',
          'brcyan',
          'brblack',
      ];
        } else {
            $this->colorNames = [
          'black',
          'red',
          'green',
          'yellow',
          'blue',
          'magenta',
          'cyan',
          'white',
          '',
          '',
          'brblack',
          'brred',
          'brgreen',
          'bryellow',
          'brblue',
          'brmagenta',
          'brcyan',
          'brwhite',
      ];
        }
    }
}
