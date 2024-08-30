<?php

namespace think\agent;

use Closure;
use think\agent\tiktoken\Encoder;

final class Util
{
    protected static ?Encoder $encoder = null;

    public static function toBytes(string $text): array
    {
        return array_map(Closure::fromCallable('hexdec'), str_split(bin2hex($text), 2));
    }

    public static function substrReplace($string, $replacement, $start, $length = null)
    {
        if (is_array($string)) {
            $num = count($string);
            // $replacement
            $replacement = is_array($replacement) ? array_slice($replacement, 0, $num) : array_pad([$replacement], $num, $replacement);
            // $start
            if (is_array($start)) {
                $start = array_slice($start, 0, $num);
                foreach ($start as $key => $value)
                    $start[$key] = is_int($value) ? $value : 0;
            } else {
                $start = array_pad([$start], $num, $start);
            }
            // $length
            if (!isset($length)) {
                $length = array_fill(0, $num, 0);
            } elseif (is_array($length)) {
                $length = array_slice($length, 0, $num);
                foreach ($length as $key => $value)
                    $length[$key] = isset($value) ? (is_int($value) ? $value : $num) : 0;
            } else {
                $length = array_pad([$length], $num, $length);
            }
            // Recursive call
            return array_map(__FUNCTION__, $string, $replacement, $start, $length);
        }
        preg_match_all('/./us', (string) $string, $smatches);
        preg_match_all('/./us', (string) $replacement, $rmatches);
        if ($length === null) $length = mb_strlen($string);
        array_splice($smatches[0], $start, $length, $rmatches[0]);
        return join($smatches[0]);
    }

    public static function fromBytes(array $bytes): string
    {
        return pack('C*', ...$bytes);
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

    public static function tikToken($messages)
    {
        $perMessage = 3;
        $perName    = 1;

        if (self::$encoder === null) {
            self::$encoder = new Encoder();
        }

        $encoder = self::$encoder;

        if (is_string($messages)) {
            return count($encoder->encode($messages));
        }

        $nums = 0;

        foreach ($messages as $message) {
            $nums += $perMessage;
            foreach ($message as $key => $value) {
                if ($key == 'tool_calls') {
                    foreach ($value as $call) {
                        foreach ($call as $cKey => $cValue) {
                            $nums += count($encoder->encode($cKey));
                            if ($cKey == 'function') {
                                foreach ($cValue as $fKey => $fValue) {
                                    $nums += count($encoder->encode($fKey));
                                    $nums += count($encoder->encode($fValue));
                                }
                            } else {
                                $nums += count($encoder->encode($cValue));
                            }
                        }
                    }
                } else {
                    if (is_array($value)) {
                        $text = '';
                        foreach ($value as $v) {
                            if (is_array($v)) {
                                switch ($v['type']) {
                                    case 'text':
                                        $text .= $v['text'];
                                        break;
                                    case 'image_url':
                                        $detail = $v['image_url']['detail'] ?? 'high';
                                        $nums   += $detail == 'low' ? 85 : 1000;
                                        break;
                                }
                            }
                        }
                        $value = $text;
                    }
                    if (is_string($value)) {
                        $nums += count($encoder->encode($value));
                    }
                }

                if ($key == 'name') {
                    $nums += $perName;
                }
            }
        }

        $nums += 3;

        return $nums;
    }

}
