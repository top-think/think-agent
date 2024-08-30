<?php

namespace think\agent;

class Credentials implements \JsonSerializable
{
    protected $data = [];

    public function __construct($data = null)
    {
        if ($data) {
            $this->data = json_decode($data, true);
        }
    }

    public static function make(array $data, array $schema)
    {
        $credentials = new self();
        $credentials->set($data, $schema);
        return $credentials;
    }

    public function set(array $data, array $schema)
    {
        //加密
        foreach ($data as $key => &$value) {
            if (isset($this->data[$key])) {
                if ($value == $this->get($key, true)) {
                    $value = $this->data[$key];
                    continue;
                }
            }
            if ($schema[$key]['encrypt'] ?? false) {
                $value = $this->encrypt($value);
            }
        }

        $this->data = $data;

        return $this;
    }

    public function get($name, $mask = false)
    {
        $value = $this->data[$name] ?? null;
        if (str_starts_with($value, '@encrypted:')) {
            $value = $this->decrypt($value);
            if ($mask) {
                return Util::maskString($value);
            }
        }
        return $value;
    }

    public function __toString(): string
    {
        return json_encode($this->data);
    }

    public function jsonSerialize(): mixed
    {
        return array_map(function ($value) {
            if (str_starts_with($value, '@encrypted:')) {
                return Util::maskString($this->decrypt($value));
            }
            return $value;
        }, $this->data);
    }

    protected function encrypt($value)
    {
        return "@encrypted:" . openssl_encrypt($value, 'AES-128-ECB', config('app.token'));
    }

    protected function decrypt($value)
    {
        return openssl_decrypt(substr($value, 11), 'AES-128-ECB', config('app.token'));
    }
}
