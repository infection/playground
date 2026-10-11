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

namespace App\Code\Validator;

use App\Code\Visitor\ForbiddenCodeCollectorVisitor;
use function array_diff;
use function array_map;
use function array_merge;
use PhpParser\Error as PhpParserError;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Lexer;
use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final class CodeValidator
{
    // https://stackoverflow.com/questions/3115559/exploitable-php-functions
    public const FORBIDDEN_FUNCTIONS = [
        'exec',
        'passthru',
        'system',
        'shell_exec',
        'popen',
        'proc_open',
        'pcntl_exec',
        'eval',
        'create_function',
        'include',
        'include_once',
        'require',
        'require_once',
        'phpinfo',
        'posix_getlogin',
        'posix_ttyname',
        'getenv',
        'get_current_user',
        'proc_get_status',
        'get_cfg_var',
        'disk_free_space',
        'disk_total_space',
        'diskfreespace',
        'getcwd',
        'getlastmo',
        'getmygid',
        'getmyinode',
        'getmypid',
        'getmyuid',
        'extract',
        'parse_str',
        'putenv',
        'ini_set',
        'mail',
        'header',
        'proc_nice',
        'proc_terminate',
        'proc_close',
        'pfsockopen',
        'fsockopen',
        'apache_child_terminate',
        'posix_kill',
        'posix_mkfifo',
        'posix_setpgid',
        'posix_setsid',
        'posix_setuid',
        'fopen',
        'tmpfile',
        'bzopen',
        'gzopen',
        'chgrp',
        'chmod',
        'chown',
        'copy',
        'file_put_contents',
        'lchgrp',
        'lchown',
        'link',
        'mkdir',
        'move_uploaded_file',
        'rename',
        'rmdir',
        'symlink',
        'tempnam',
        'touch',
        'unlink',
        'imagepng',
        'imagewbmp',
        'image2wbmp',
        'imagejpeg',
        'imagexbm',
        'imagegif',
        'imagegd',
        'imagegd2',
        'iptcembed',
        'ftp_get',
        'ftp_nb_get',
        'file_exists',
        'file_get_contents',
        'file',
        'fileatime',
        'filectime',
        'filegroup',
        'fileinode',
        'filemtime',
        'fileowner',
        'fileperms',
        'filesize',
        'filetype',
        'glob',
        'is_dir',
        'is_executable',
        'is_file',
        'is_link',
        'is_readable',
        'is_uploaded_file',
        'is_writable',
        'is_writeable',
        'linkinfo',
        'lstat',
        'parse_ini_file',
        'pathinfo',
        'readfile',
        'readlink',
        'realpath',
        'stat',
        'gzfile',
        'readgzfile',
        'getimagesize',
        'imagecreatefromgif',
        'imagecreatefromjpeg',
        'imagecreatefrompng',
        'imagecreatefromwbmp',
        'imagecreatefromxbm',
        'imagecreatefromxpm',
        'ftp_put',
        'ftp_nb_put',
        'exif_read_data',
        'read_exif_data',
        'exif_thumbnail',
        'exif_imagetype',
        'hash_file',
        'hash_hmac_file',
        'hash_update_file',
        'md5_file',
        'sha1_file',
        'highlight_file',
        'show_source',
        'php_strip_whitespace',
        'get_meta_tags',
    ];

    private Parser $parser;

    private Collecting $errorHandler;

    public function __construct()
    {
        $lexer = new Lexer([
            'usedAttributes' => ['comments', 'startLine', 'endLine', 'startFilePos', 'endFilePos'],
        ]);

        $this->parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7, $lexer);
        $this->errorHandler = new Collecting();
    }

    /**
     * @return Error[]
     */
    public function validate(string $code): array
    {
        $this->errorHandler->clearErrors();

        $statements = $this->parser->parse($code, $this->errorHandler);

        $errorCollectorVisitor = new ForbiddenCodeCollectorVisitor();

        if ($statements !== null) {
            $traverser = new NodeTraverser();
            $traverser->addVisitor($errorCollectorVisitor);

            $traverser->traverse($statements);
        }

        $errors = array_map(
            static function (PhpParserError $phpParserError): Error {
                return Error::inSyntax($phpParserError->getMessage(), $phpParserError->getLine());
            },
            $this->errorHandler->getErrors()
        );

        return array_merge(
            $errors,
            $errorCollectorVisitor->getErrors()
        );
    }

    /**
     * @return list<string>
     */
    public static function getListOfDisabledFunctionsOnPhpLevel(): array
    {
        return array_diff(
            self::FORBIDDEN_FUNCTIONS,
            [
                // functions needed for PHPUnit itself when Infection runs it
                'getenv',
                'putenv',
                'realpath',
                'is_file',
                'is_readable',
                'file_get_contents',
                'file_put_contents',
                'is_dir',
                'mkdir',
                'fopen',
                'ini_set',
                'file_exists',
                'getcwd',
                'glob',
            ]
        );
    }
}
