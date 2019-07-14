<?php

declare(strict_types=1);

namespace App\Utils;

class DirectoryCreator
{
    public function create(string $dirName): string
    {
        $path = sprintf(
            '%s/infection/%s',
            sys_get_temp_dir(),
            $dirName
        );

        if (!@mkdir($path, 0777, true) && !is_dir($path)) {
            throw new \RuntimeException('Can not create temp dir');
        }

        return $path;
    }
}
