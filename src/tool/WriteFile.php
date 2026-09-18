<?php

namespace think\agent\tool;

use think\agent\Sandbox;

class WriteFile extends FunctionCall
{
    public $title = 'WriteFile';
    public $description = 'Write text content to a file. ONLY supports writing plain text files (such as .txt, .log, .json, .xml, .csv, .md, etc.). Cannot write binary files (images, videos, compressed files, etc.). Use for creating new text files or modifying existing text files.';
    public $parameters = [
        'path'    => [
            'type'        => 'string',
            'description' => 'Absolute path of the text file to write. Must be a plain text file format.',
            'required'    => true,
        ],
        'content' => [
            'type'        => 'string',
            'description' => 'Plain text content to write to the file',
            'required'    => true,
        ],
    ];

    public function __construct(protected Sandbox $sandbox)
    {
    }

    protected function run(Args $args)
    {
        $path    = $args->get('path');
        $content = $args->get('content');

        $this->sandbox->writeFile($path, $content);

        return 'File write successfully.';
    }
}