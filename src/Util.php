<?php

namespace think\agent;

final class Util
{

    public static function substrReplace($string, $replacement, $start, $length = null)
    {
        if (is_array($string)) {
            $num = count($string);
            // $replacement
            $replacement = is_array($replacement) ? array_slice($replacement, 0, $num) : array_pad([$replacement], $num, $replacement);
            // $start
            if (is_array($start)) {
                $start = array_slice($start, 0, $num);
                foreach ($start as $key => $value) {
                    $start[$key] = is_int($value) ? $value : 0;
                }
            } else {
                $start = array_pad([$start], $num, $start);
            }
            // $length
            if (!isset($length)) {
                $length = array_fill(0, $num, 0);
            } elseif (is_array($length)) {
                $length = array_slice($length, 0, $num);
                foreach ($length as $key => $value) {
                    $length[$key] = isset($value) ? (is_int($value) ? $value : $num) : 0;
                }
            } else {
                $length = array_pad([$length], $num, $length);
            }

            // Recursive call
            return array_map(__FUNCTION__, $string, $replacement, $start, $length);
        }
        preg_match_all('/./us', (string)$string, $smatches);
        preg_match_all('/./us', (string)$replacement, $rmatches);
        if (null === $length) {
            $length = mb_strlen($string);
        }
        array_splice($smatches[0], $start, $length, $rmatches[0]);

        return join($smatches[0]);
    }


    public static function maskString($string)
    {
        $strLen = mb_strlen($string);
        if ($strLen <= 4) {
            $start = 0;
        } else {
            $start = 2;
        }
        $length = $strLen - $start * 2;

        return self::substrReplace($string, str_repeat('*', $length), $start, $length);
    }

    public static function tikTokens($messages)
    {
        if (is_string($messages)) {
            return (int)ceil(mb_strlen($messages) / 4);
        }

        $nums = 0;

        foreach ($messages as $message) {
            $nums += self::estimateTokens($message);
        }

        return $nums;
    }

    /**
     * Estimate token count for one message using a conservative character heuristic.
     * Approximates 1 token per 4 characters.
     */
    protected static function estimateTokens($message)
    {
        $chars = 0;
        $role  = $message['role'] ?? '';

        switch ($role) {
            case 'system':
            case 'user':
            {
                $content = $message['content'] ?? '';
                if (is_string($content)) {
                    $chars = mb_strlen($content);
                } elseif (is_array($content)) {
                    foreach ($content as $block) {
                        if (is_array($block)) {
                            switch ($block['type'] ?? null) {
                                case 'text':
                                    $chars += mb_strlen($block['text'] ?? '');
                                    break;
                                case 'image_url':
                                    $chars += 4800;
                                    break;
                            }
                        }
                    }
                }
                return (int)ceil($chars / 4);
            }
            case 'assistant':
            {
                $content = $message['content'] ?? '';
                if (is_string($content)) {
                    $chars += mb_strlen($content);
                }
                // reasoning (thinking) content
                if (isset($message['reasoning'])) {
                    $chars += mb_strlen($message['reasoning']);
                }
                // tool calls
                if (!empty($message['tool_calls'])) {
                    foreach ($message['tool_calls'] as $call) {
                        $callType = $call['type'] ?? 'function';
                        if ($callType === 'function' && isset($call['function'])) {
                            $chars += mb_strlen($call['function']['name'] ?? '');
                            $chars += mb_strlen($call['function']['arguments'] ?? '');
                        } else {
                            // plugin / mcp etc.
                            if (isset($call[$callType])) {
                                $chars += mb_strlen($call[$callType]['function'] ?? '');
                                $chars += mb_strlen($call[$callType]['arguments'] ?? '');
                            }
                        }
                    }
                }
                return (int)ceil($chars / 4);
            }
            case 'tool':
            {
                $chars = mb_strlen($message['content'] ?? '');
                return (int)ceil($chars / 4);
            }
        }

        return 0;
    }
}
