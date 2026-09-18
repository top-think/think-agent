<?php

namespace think\agent\tool;

use think\agent\Sandbox;

class Browser extends FunctionCall
{
    public $title = 'Browser';
    public $description = 'Control a web browser to navigate pages, click elements, fill forms, take screenshots, and extract page content. Use for web scraping, testing, form submission, or any task requiring browser interaction.';
    public $parameters = [
        'action' => [
            'type'        => 'string',
            'description' => 'The browser action to perform.',
            'required'    => true,
            'enum'        => ['open', 'goto', 'click', 'dblclick', 'fill', 'type', 'press', 'keydown', 'keyup', 'hover', 'check', 'uncheck', 'select', 'drag', 'snapshot', 'screenshot', 'eval', 'resize', 'tab-new', 'tab-close', 'tab-select', 'dialog-accept', 'dialog-dismiss', 'go-back', 'go-forward', 'reload', 'mousemove', 'mousedown', 'mouseup', 'mousewheel'],
        ],
        'params' => [
            'type'        => 'string',
            'description' => 'The argument(s) for the action: open/goto/tab-new → URL; click/dblclick/hover/check/uncheck → element ref (e.g. "e15"); fill → "ref text" (ref then text separated by space); type → text to type; press → key name (e.g. "Enter"); select → "ref value"; eval → JavaScript code; screenshot → optional filename; resize → "width height"; drag → "startRef endRef"; tab-close/tab-select → tab index; mousemove → "x y"; mousedown/mouseup → optional button; mousewheel → "dx dy". Leave empty when not needed.',
            'required'    => false,
        ],
        'intent' => [
            'type'        => 'string',
            'description' => 'Describe the purpose of this browser action in first person, e.g., "I am navigating to the login page"',
            'required'    => true,
        ],
    ];

    protected string $profileDir;

    public function __construct(protected Sandbox $sandbox, protected ?string $workDir = null)
    {
        $this->profileDir = ($this->workDir ?: '/workspace') . '/.browser-profile';
    }

    protected function run(Args $args)
    {
        $action = $args->get('action');
        $params = $args->get('params', '');

        $body = $this->buildBody($action, $params);

        $result = $this->sandbox->browserExecute($body);

        return $result;
    }

    protected function buildBody(string $action, string $params): array
    {
        $body = ['action' => $action];

        switch ($action) {
            case 'open':
                $body['url']     = $params;
                $body['profile'] = $this->profileDir;
                break;
            case 'goto':
                $body['url'] = $params;
                break;
            case 'click':
            case 'dblclick':
            case 'hover':
            case 'check':
            case 'uncheck':
                $body['ref'] = $params;
                break;
            case 'fill':
                $parts = explode(' ', $params, 2);
                $body['ref']  = $parts[0];
                $body['text'] = $parts[1] ?? '';
                break;
            case 'type':
                $body['text'] = $params;
                break;
            case 'press':
            case 'keydown':
            case 'keyup':
                $body['key'] = $params;
                break;
            case 'select':
                $parts = explode(' ', $params, 2);
                $body['ref']   = $parts[0];
                $body['value'] = $parts[1] ?? '';
                break;
            case 'eval':
                $body['script'] = $params;
                break;
            case 'screenshot':
            case 'snapshot':
                if ($params) {
                    $body['filename'] = $params;
                }
                break;
            case 'drag':
                $parts = explode(' ', $params, 2);
                $body['start_ref'] = $parts[0];
                $body['end_ref']   = $parts[1] ?? '';
                break;
            case 'resize':
                $parts = explode(' ', $params, 2);
                $body['width']  = (int) ($parts[0] ?? 0);
                $body['height'] = (int) ($parts[1] ?? 0);
                break;
            case 'tab-close':
            case 'tab-select':
                $body['index'] = (int) $params;
                break;
            case 'tab-new':
                if ($params) {
                    $body['url'] = $params;
                }
                break;
            case 'mousemove':
                $parts = explode(' ', $params, 2);
                $body['x'] = (int) ($parts[0] ?? 0);
                $body['y'] = (int) ($parts[1] ?? 0);
                break;
            case 'mousedown':
            case 'mouseup':
                if ($params) {
                    $body['button'] = $params;
                }
                break;
            case 'mousewheel':
                $parts = explode(' ', $params, 2);
                $body['dx'] = (int) ($parts[0] ?? 0);
                $body['dy'] = (int) ($parts[1] ?? 0);
                break;
        }

        return $body;
    }
}
