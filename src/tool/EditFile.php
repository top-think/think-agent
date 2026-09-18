<?php

namespace think\agent\tool;

use think\agent\Sandbox;

class EditFile extends FunctionCall
{
    public $title       = 'EditFile';
    public $description = 'Edit text file content. ONLY supports plain text files (such as .txt, .log, .json, .xml, .csv, .md, etc.). Cannot edit binary files (images, videos, compressed files, etc.). Use for modifying configuration files, updating logs, or changing documentation.';
    public $parameters  = [
        'path'    => [
            'type'        => 'string',
            'description' => 'Absolute path of the text file to edit. Must be a plain text file format.',
            'required'    => true,
        ],
        'old_str' => [
            'type'        => 'string',
            'description' => 'The string in the file that needs to be replaced.',
            'required'    => true,
        ],
        'new_str' => [
            'type'        => 'string',
            'description' => 'The new string to replace the old string with.',
            'required'    => true,
        ],
    ];

    public function __construct(protected Sandbox $sandbox) {}

    protected function run(Args $args)
    {
        $path   = $args->get('path');
        $oldStr = $args->get('old_str');
        $newStr = $args->get('new_str');

        $this->sandbox->editFile($path, $oldStr, $newStr);

        return 'File updated successfully.';
    }
}
