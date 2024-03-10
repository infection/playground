<?php
/**
 * This code is licensed under the BSD 3-Clause License.
 *
 * Copyright (c) 2017, Maks Rafalko
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * * Redistributions of source code must retain the above copyright notice, this
 *   list of conditions and the following disclaimer.
 *
 * * Redistributions in binary form must reproduce the above copyright notice,
 *   this list of conditions and the following disclaimer in the documentation
 *   and/or other materials provided with the distribution.
 *
 * * Neither the name of the copyright holder nor the names of its
 *   contributors may be used to endorse or promote products derived from
 *   this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

declare(strict_types=1);

namespace App\Html\Ansi;

use function implode;
use function sprintf;

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
            'black' => 'black',
            'red' => 'darkred',
            'green' => 'green',
            'yellow' => 'yellow',
            'blue' => 'blue',
            'magenta' => 'darkmagenta',
            'cyan' => 'cyan',
            'white' => 'white',
            'brblack' => 'black',
            'brred' => 'red',
            'brgreen' => 'lightgreen',
            'bryellow' => 'lightyellow',
            'brblue' => 'lightblue',
            'brmagenta' => 'magenta',
            'brcyan' => 'lightcyan',
            'brwhite' => 'white',
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
    public function setPrefix($prefix): void
    {
        $this->prefix = $prefix;
    }
}
