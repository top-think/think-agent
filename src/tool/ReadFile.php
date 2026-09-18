<?php

namespace think\agent\tool;

use think\agent\Sandbox;

class ReadFile extends FunctionCall
{
    public $title       = 'ReadFile';
    public $description = 'Read text file content. ONLY supports plain text files (such as .txt, .log, .json, .xml, .csv, .md, etc.). Cannot read binary files (images, videos, compressed files, etc.). Use for checking file contents, analyzing logs, or reading configuration files.';
    public $parameters  = [
        'path' => [
            'type'        => 'string',
            'description' => 'Absolute path of the text file to read. Must be a plain text file format.',
            'required'    => true,
        ],
        'offset' => [
            'type'        => 'integer',
            'description' => 'Start line (0-based). Defaults to 0 (read from the beginning).',
            'required'    => false,
        ],
        'limit' => [
            'type'        => 'integer',
            'description' => 'Number of lines to return. -1 means use the default limit (2000 lines / 50KB). Defaults to -1.',
            'required'    => false,
        ],
    ];

    public function __construct(protected Sandbox $sandbox)
    {
    }

    protected function run(Args $args)
    {
        $path   = $args->get('path');
        $offset = $args->get('offset', 0);
        $limit  = $args->get('limit', -1);

        return $this->sandbox->readFile($path, $offset, $limit);
    }
}