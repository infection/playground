<?php

declare(strict_types=1);

namespace App\Code;

use PhpParser\Error;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Lexer;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final class CodeValidator
{
    /**
     * @var Parser
     */
    private $parser;

    /**
     * @var Collecting
     */
    private $errorHandler;

    public function __construct()
    {
        $lexer = new Lexer([
            'usedAttributes' => ['comments', 'startLine', 'endLine', 'startFilePos', 'endFilePos'],
        ]);

        $this->parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7, $lexer);
        $this->errorHandler = new Collecting();
    }

    /**
     * @param string $code
     *
     * @return Error[]
     */
    public function validate(string $code): array
    {
        $this->errorHandler->clearErrors();

        $this->parser->parse($code, $this->errorHandler);

        return $this->errorHandler->getErrors();
    }
}
