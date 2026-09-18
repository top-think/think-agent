<?php

namespace think\agent\tool;

use think\agent\Sandbox;
use think\agent\tool\result\Error;

class Shell extends FunctionCall
{
    public $title = 'Shell';
    public $description = 'Execute commands in a specified shell session. Use for running code, installing packages, or managing files.';
    public $parameters = [
        'command' => [
            'type'        => 'string',
            'description' => 'Shell command to execute',
            'required'    => true,
        ],
        'intent'  => [
            'type'        => 'string',
            'description' => 'Describe the purpose of executing this command in first person, e.g., "I am installing dependencies", "I am checking the server status"',
            'required'    => true,
        ],
    ];

    public function __construct(protected Sandbox $sandbox)
    {
    }

    protected function run(Args $args)
    {
        $command = $args->get('command');

        $result = $this->sandbox->runCommand($command, true);

        $data = [
            'output' => ''
        ];

        foreach ($result as $event) {
            switch ($event['type']) {
                case 'stdout':
                case 'stderr':
                    $data['output'] .= $event['content'];

                    yield [
                        'output' => $event['content'],
                    ];
                    break;
                case 'exit':
                    $data['exit_code'] = $event['exit_code'];
                    break;
                case 'error':
                    $data['error'] = $event['message'];
                    break;
            }
        }

        if (!empty($data['error'])) {
            return new Error($data['error']);
        }

        // 默认限制输出行数，避免消耗过多 token
        $data['output'] = $this->limitLines($data['output']);

        return $data;
    }

    /**
     * 限制输出内容：整体内容不大时原样返回，超量时才截断（超过50行保留前20行和后30行，单行超过500字符截断）
     */
    protected function limitLines(string $content): string
    {
        if (empty($content)) {
            return $content;
        }

        $maxTotalLength = 20000;
        $maxLines       = 50;
        $maxLineLength  = 500;

        $lines      = explode("\n", $content);
        $totalLines = count($lines);

        // 整体内容不大时直接原样返回，避免误伤少量长行
        if ($totalLines <= $maxLines && mb_strlen($content) <= $maxTotalLength) {
            return $content;
        }

        // 截断过长的单行
        foreach ($lines as &$line) {
            if (mb_strlen($line) > $maxLineLength) {
                $line = mb_substr($line, 0, $maxLineLength) . '...(已截断' . (mb_strlen($line) - $maxLineLength) . '字符)';
            }
        }
        unset($line);

        if ($totalLines <= $maxLines) {
            return implode("\n", $lines);
        }

        $firstPart    = array_slice($lines, 0, 20);
        $lastPart     = array_slice($lines, -30);
        $omittedLines = $totalLines - $maxLines;

        return implode("\n", $firstPart) . "\n(...已截断{$omittedLines}行)\n" . implode("\n", $lastPart);
    }
}
